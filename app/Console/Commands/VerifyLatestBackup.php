<?php

namespace App\Console\Commands;

use App\Application\Backups\VerifyBackupArchive;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('backup:verify-latest')]
#[Description('Verify the integrity and database dump of the latest backup archive.')]
class VerifyLatestBackup extends Command
{
    public function handle(VerifyBackupArchive $verifier): int
    {
        $disk = Storage::disk('backups');
        $folder = (string) config('backup.backup.name');
        $path = collect($disk->allFiles($folder))->sortByDesc(fn (string $file): int => $disk->lastModified($file))->first();
        if (! is_string($path)) {
            $this->error('No backup archive is available to verify.');

            return self::FAILURE;
        }

        $verification = $verifier->handle($path);
        if ($verification->status !== 'verified') {
            $this->error('Backup verification failed: '.$verification->error);

            return self::FAILURE;
        }

        $this->info("Verified {$path} ({$verification->entries} entries). ");

        return self::SUCCESS;
    }
}
