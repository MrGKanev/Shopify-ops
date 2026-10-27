<?php

namespace App\Application\Backups;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PDO;
use RuntimeException;
use ZipArchive;

class RestoreBackupArchive
{
    /** @return array{database_dump:string,restored_files:int} */
    public function handle(string $path, ?string $expectedChecksum = null): array
    {
        $disk = Storage::disk('backups');
        $folder = (string) config('backup.backup.name');
        if (! in_array($path, $disk->allFiles($folder), true)) {
            throw new RuntimeException('The backup archive no longer exists.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'shopify-ops-restore-');
        if ($temporaryPath === false) {
            throw new RuntimeException('A temporary restore file could not be created.');
        }

        $stagingDirectory = sys_get_temp_dir().'/shopify-ops-restore-'.bin2hex(random_bytes(16));
        File::ensureDirectoryExists($stagingDirectory, 0700);

        try {
            $this->downloadArchive($disk, $path, $temporaryPath);
            if ($expectedChecksum !== null && ! hash_equals($expectedChecksum, (string) hash_file('sha256', $temporaryPath))) {
                throw new RuntimeException('The backup archive changed after it was verified.');
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

                $databaseDump = $this->extractDatabaseDump($zip);
                $stagedFiles = $this->stageApplicationFiles($zip, $stagingDirectory);
            } finally {
                $zip->close();
            }

            $this->restoreDatabase($databaseDump['contents']);
            $restoredFiles = 0;
            foreach ($stagedFiles as $file) {
                $stream = fopen($file['staged_path'], 'rb');
                if ($stream === false) {
                    throw new RuntimeException('A staged restore file could not be read.');
                }
                try {
                    if (! Storage::disk($file['disk'])->put($file['path'], $stream)) {
                        throw new RuntimeException('An application file could not be restored.');
                    }
                    $restoredFiles++;
                } finally {
                    fclose($stream);
                }
            }

            return ['database_dump' => $databaseDump['name'], 'restored_files' => $restoredFiles];
        } finally {
            File::deleteDirectory($stagingDirectory);
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function downloadArchive(Filesystem $disk, string $path, string $temporaryPath): void
    {
        $source = $disk->readStream($path);
        if (! is_resource($source)) {
            throw new RuntimeException('The backup archive could not be streamed for restore.');
        }
        $destination = fopen($temporaryPath, 'wb');
        if ($destination === false) {
            fclose($source);
            throw new RuntimeException('The backup archive could not be streamed for restore.');
        }
        try {
            stream_copy_to_stream($source, $destination);
        } finally {
            fclose($source);
            fclose($destination);
        }
    }

    /** @return array{name:string,contents:string} */
    private function extractDatabaseDump(ZipArchive $zip): array
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            if (! is_array($entry) || ! str_starts_with($entry['name'], 'db-dumps/') || str_ends_with($entry['name'], '/')) {
                continue;
            }

            $contents = $zip->getFromIndex($index);
            if ($contents === false) {
                throw new RuntimeException("Archive entry {$entry['name']} could not be read.");
            }

            return ['name' => $entry['name'], 'contents' => $contents];
        }

        throw new RuntimeException('No database dump was found in the backup archive.');
    }

    /** @return list<array{disk:string,path:string,staged_path:string}> */
    private function stageApplicationFiles(ZipArchive $zip, string $stagingDirectory): array
    {
        $markers = ['storage/app/private/' => 'local', 'storage/app/public/' => 'public'];
        $files = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            if (! is_array($entry) || str_ends_with($entry['name'], '/')) {
                continue;
            }
            $marker = null;
            $markerPosition = PHP_INT_MAX;
            foreach (array_keys($markers) as $candidate) {
                $position = strpos($entry['name'], $candidate);
                if ($position !== false && $position < $markerPosition) {
                    $marker = $candidate;
                    $markerPosition = $position;
                }
            }
            if ($marker === null) {
                continue;
            }
            $relative = substr($entry['name'], $markerPosition + strlen($marker));
            if ($relative === '' || str_contains($relative, chr(0)) || str_contains($relative, '\\') || in_array('..', explode('/', $relative), true)) {
                continue;
            }
            $contents = $zip->getFromIndex($index);
            if ($contents === false) {
                throw new RuntimeException("Archive entry {$entry['name']} could not be read.");
            }
            $stagedPath = $stagingDirectory.'/'.$index;
            if (File::put($stagedPath, $contents) === false) {
                throw new RuntimeException('An application file could not be staged for restore.');
            }
            $files[] = ['disk' => $markers[$marker], 'path' => $relative, 'staged_path' => $stagedPath];
        }

        return $files;
    }

    private function restoreDatabase(string $sql): void
    {
        $connectionName = (string) config('database.default');
        $connection = (array) config("database.connections.{$connectionName}");
        $driver = (string) ($connection['driver'] ?? '');

        match ($driver) {
            'sqlite' => $this->restoreSqlite($connectionName, $connection, $sql),
            'mysql', 'mariadb' => $this->restoreMysql($connection, $sql),
            default => throw new RuntimeException("Restoring the '{$driver}' driver is not supported."),
        };
    }

    /** @param array<string,mixed> $connection */
    private function restoreSqlite(string $connectionName, array $connection, string $sql): void
    {
        $database = (string) ($connection['database'] ?? '');

        if ($database === '' || $database === ':memory:') {
            $pdo = DB::connection($connectionName)->getPdo();
            $pdo->exec('PRAGMA foreign_keys = OFF;');
            $pdo->exec($sql);

            return;
        }

        DB::purge($connectionName);
        File::ensureDirectoryExists(dirname($database));
        File::put($database, '');

        $pdo = new PDO('sqlite:'.$database);
        $pdo->exec('PRAGMA foreign_keys = OFF;');
        $pdo->exec($sql);

        DB::purge($connectionName);
    }

    /** @param array<string,mixed> $connection */
    private function restoreMysql(array $connection, string $sql): void
    {
        $command = [
            'mysql',
            '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
            '--port='.(string) ($connection['port'] ?? '3306'),
            '--user='.(string) ($connection['username'] ?? 'root'),
            (string) $connection['database'],
        ];

        $password = (string) ($connection['password'] ?? '');

        $result = Process::env($password !== '' ? ['MYSQL_PWD' => $password] : [])
            ->input($sql)
            ->run($command);

        if ($result->failed()) {
            throw new RuntimeException('The mysql client failed to restore the dump: '.$result->errorOutput());
        }
    }
}
