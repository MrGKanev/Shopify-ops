<?php

namespace App\Console\Commands;

use App\Application\Backups\RestoreBackupArchive;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('backup:restore {path? : The backup archive path relative to the backups disk} {--force : Restore without a confirmation prompt}')]
#[Description('Restore the database and application files from a backup archive.')]
class RestoreBackupCommand extends Command
{
    public function handle(RestoreBackupArchive $restorer): int
    {
        $path = $this->argument('path');
        if (! is_string($path) || $path === '') {
            $path = $this->latestBackupPath();
            if ($path === null) {
                $this->error('No backup archive is available to restore.');

                return self::FAILURE;
            }
        }

        if (! $this->option('force') && ! $this->confirm("This overwrites the current database and stored files with the contents of {$path}. Continue?")) {
            $this->warn('Restore cancelled.');

            return self::FAILURE;
        }

        try {
            $result = $restorer->handle($path);
        } catch (Throwable $exception) {
            $this->error('Restore failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Restored {$result['database_dump']} and {$result['restored_files']} file(s) from {$path}.");

        return self::SUCCESS;
    }

    private function latestBackupPath(): ?string
    {
        $disk = Storage::disk('backups');
        $folder = (string) config('backup.backup.name');

        $path = collect($disk->allFiles($folder))
            ->sortByDesc(fn (string $file): int => $disk->lastModified($file))
            ->first();

        return is_string($path) ? $path : null;
    }
}
