<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Tests\TestCase;

class BackupOperationsTest extends TestCase
{
    public function test_backups_use_a_dedicated_verified_destination(): void
    {
        $this->assertSame(['backups'], config('backup.backup.destination.disks'));
        $this->assertSame(storage_path('app/private'), config('backup.backup.source.files.include.0'));
        $this->assertSame(storage_path('app/public'), config('backup.backup.source.files.include.1'));
        $this->assertContains(storage_path('app/backups'), config('backup.backup.source.files.exclude'));
        $this->assertTrue(config('backup.backup.verify_backup'));
        $this->assertSame('aes256', config('backup.backup.encryption'));
        $this->assertSame([], config('backup.notifications.notifications.'.BackupHasFailedNotification::class));
    }

    public function test_an_empty_archive_password_env_value_normalizes_to_null(): void
    {
        $original = getenv('BACKUP_ARCHIVE_PASSWORD');

        try {
            putenv('BACKUP_ARCHIVE_PASSWORD=');
            $config = require config_path('backup.php');

            $this->assertNull($config['backup']['password']);
        } finally {
            putenv($original === false ? 'BACKUP_ARCHIVE_PASSWORD' : "BACKUP_ARCHIVE_PASSWORD={$original}");
        }
    }

    public function test_backup_operations_are_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->pluck('command')
            ->filter()
            ->implode("\n");

        $this->assertStringContainsString('backup:run --only-db', $commands);
        $this->assertStringContainsString('backup:run', $commands);
        $this->assertStringContainsString('backup:monitor', $commands);
        $this->assertStringContainsString('backup:clean', $commands);
    }
}
