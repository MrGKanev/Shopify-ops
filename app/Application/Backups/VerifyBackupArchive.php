<?php

namespace App\Application\Backups;

use App\Models\BackupVerification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

class VerifyBackupArchive
{
    public function handle(string $path): BackupVerification
    {
        $startedAt = hrtime(true);
        $archiveSize = null;
        $checksum = null;
        $entries = null;
        $databaseDump = null;
        $status = 'failed';
        $error = null;
        $temporaryPath = null;

        try {
            $disk = Storage::disk('backups');
            $folder = (string) config('backup.backup.name');
            if (! in_array($path, $disk->allFiles($folder), true)) {
                throw new RuntimeException('The backup archive no longer exists.');
            }

            $archiveSize = $disk->size($path);
            $temporaryPath = tempnam(sys_get_temp_dir(), 'shopify-ops-backup-');
            if ($temporaryPath === false) {
                throw new RuntimeException('A temporary verification file could not be created.');
            }

            $source = $disk->readStream($path);
            if (! is_resource($source)) {
                throw new RuntimeException('The backup archive could not be streamed for verification.');
            }
            $destination = fopen($temporaryPath, 'wb');
            if ($destination === false) {
                fclose($source);
                throw new RuntimeException('The backup archive could not be streamed for verification.');
            }
            try {
                if (stream_copy_to_stream($source, $destination) !== $archiveSize) {
                    throw new RuntimeException('The copied archive size does not match the stored backup.');
                }
            } finally {
                fclose($source);
                fclose($destination);
            }

            $checksum = hash_file('sha256', $temporaryPath);
            if ($checksum === false) {
                throw new RuntimeException('The archive checksum could not be calculated.');
            }

            $zip = new ZipArchive;
            $opened = $zip->open($temporaryPath, ZipArchive::CHECKCONS);
            if ($opened !== true) {
                throw new RuntimeException("ZIP integrity check failed with code {$opened}.");
            }
            try {
                $password = config('backup.backup.password');
                if (is_string($password) && $password !== '') {
                    $zip->setPassword($password);
                }
                $entries = $zip->numFiles;
                if ($entries < 1) {
                    throw new RuntimeException('The backup archive is empty.');
                }

                for ($index = 0; $index < $entries; $index++) {
                    $entry = $zip->statIndex($index);
                    if (! is_array($entry) || str_ends_with($entry['name'], '/')) {
                        continue;
                    }
                    $stream = $zip->getStream($entry['name']);
                    if ($stream === false) {
                        throw new RuntimeException("Archive entry {$entry['name']} could not be read.");
                    }
                    $preview = '';
                    $readBytes = 0;
                    try {
                        while (! feof($stream)) {
                            $chunk = fread($stream, 1024 * 1024);
                            if ($chunk === false) {
                                throw new RuntimeException("Archive entry {$entry['name']} is damaged.");
                            }
                            $readBytes += strlen($chunk);
                            if (str_starts_with($entry['name'], 'db-dumps/') && strlen($preview) < 65536) {
                                $preview .= substr($chunk, 0, 65536 - strlen($preview));
                            }
                        }
                    } finally {
                        fclose($stream);
                    }
                    if ($readBytes !== $entry['size']) {
                        throw new RuntimeException("Archive entry {$entry['name']} has an invalid size.");
                    }
                    if (str_starts_with($entry['name'], 'db-dumps/')) {
                        $databaseDump = $entry['name'];
                        if ($readBytes < 1 || ! preg_match('/\b(BEGIN|CREATE|INSERT|PRAGMA|COPY|LOCK TABLES|USE)\b/i', $preview)) {
                            throw new RuntimeException('The database dump does not contain a recognizable restore statement.');
                        }
                    }
                }

                if ($databaseDump === null) {
                    throw new RuntimeException('No database dump was found in the backup archive.');
                }
            } finally {
                $zip->close();
            }

            $status = 'verified';
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        } finally {
            if (is_string($temporaryPath) && is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }

        return BackupVerification::create([
            'path' => $path,
            'status' => $status,
            'archive_size' => $archiveSize,
            'checksum' => $checksum,
            'entries' => $entries,
            'database_dump' => $databaseDump,
            'error' => $error,
            'duration_ms' => max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
            'verified_at' => now(),
        ]);
    }
}
