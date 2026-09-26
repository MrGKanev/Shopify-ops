<?php

namespace App\Jobs;

use App\Application\Backups\RestoreBackupArchive;
use App\Application\Backups\VerifyBackupArchive;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class RestoreBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $operationId,
        public string $path,
        public string $expectedChecksum,
        public string $requestedBy,
    ) {}

    public function handle(VerifyBackupArchive $verifier, RestoreBackupArchive $restorer): void
    {
        $operationPath = storage_path('app/backup-operations/'.$this->operationId.'.json');
        $lockPath = storage_path('app/backup-operations/restore.lock');
        if (! is_dir(dirname($lockPath))) {
            mkdir(dirname($lockPath), 0700, true);
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            $this->updateOperation($operationPath, ['status' => 'failed', 'error' => 'Another restore is already running.']);

            return;
        }

        $maintenanceEnabled = false;

        try {
            $this->updateOperation($operationPath, ['status' => 'running', 'started_at' => now()->toIso8601String()]);
            $verification = $verifier->handle($this->path);
            if ($verification->status !== 'verified' || ! hash_equals($this->expectedChecksum, (string) $verification->checksum)) {
                if ($verification->status === 'verified') {
                    $verification->update(['status' => 'failed', 'error' => 'The archive checksum changed after the administrator approved it.']);
                }

                throw new RuntimeException('The selected backup changed or failed verification.');
            }

            $disk = Storage::disk('backups');
            $folder = (string) config('backup.backup.name');
            $existingArchives = $disk->allFiles($folder);
            if (Artisan::call('backup:run', ['--isolated' => true, '--disable-notifications' => true]) !== 0) {
                throw new RuntimeException('The pre-restore safety backup could not be created.');
            }

            $newArchives = array_values(array_diff($disk->allFiles($folder), $existingArchives));
            if ($newArchives === []) {
                throw new RuntimeException('The pre-restore safety backup was not found.');
            }
            usort($newArchives, fn (string $left, string $right): int => $disk->lastModified($right) <=> $disk->lastModified($left));
            $safetyPath = $newArchives[0];
            $safetyVerification = $verifier->handle($safetyPath);
            if ($safetyVerification->status !== 'verified' || ! is_string($safetyVerification->checksum)) {
                throw new RuntimeException('The pre-restore safety backup failed verification.');
            }

            if (Artisan::call('down', ['--retry' => 60]) !== 0) {
                throw new RuntimeException('The application could not be placed in maintenance mode.');
            }
            $maintenanceEnabled = true;

            $result = $restorer->handle($this->path, $this->expectedChecksum);
            if (Artisan::call('up') !== 0) {
                throw new RuntimeException('The application could not leave maintenance mode.');
            }
            $maintenanceEnabled = false;

            $this->updateOperation($operationPath, [
                'status' => 'completed',
                'safety_backup' => $safetyPath,
                'result' => $result,
                'finished_at' => now()->toIso8601String(),
            ]);
            Log::notice('Backup restored from the administration panel.', [
                'operation_id' => $this->operationId,
                'path' => $this->path,
                'safety_backup' => $safetyPath,
                'requested_by' => $this->requestedBy,
            ]);
        } catch (Throwable $exception) {
            if ($maintenanceEnabled && Artisan::call('up') === 0) {
                $maintenanceEnabled = false;
            }

            $this->updateOperation($operationPath, [
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'finished_at' => now()->toIso8601String(),
            ]);
            Log::error('Backup restore from the administration panel failed.', [
                'operation_id' => $this->operationId,
                'path' => $this->path,
                'requested_by' => $this->requestedBy,
                'exception' => $exception,
            ]);
        } finally {
            if ($maintenanceEnabled) {
                Artisan::call('up');
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string,mixed> $changes */
    private function updateOperation(string $path, array $changes): void
    {
        $current = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        File::put($path, json_encode([...$current, ...$changes], JSON_THROW_ON_ERROR));
        chmod($path, 0600);
    }
}
