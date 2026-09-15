<?php

namespace Tests\Feature\Console\Commands;

use App\Models\BackupVerification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class VerifyLatestBackupTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_fails_cleanly_when_no_backup_exists(): void
    {
        Storage::fake('backups');

        $this->artisan('backup:verify-latest')
            ->expectsOutput('No backup archive is available to verify.')
            ->assertFailed();

        $this->assertSame(0, BackupVerification::query()->count());
    }

    public function test_it_verifies_the_latest_available_backup(): void
    {
        Storage::fake('backups');
        $folder = (string) config('backup.backup.name');
        $path = "{$folder}/latest.zip";
        $absolutePath = Storage::disk('backups')->path($path);
        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }
        $zip = new ZipArchive;
        $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('db-dumps/sqlite-sqlite-database.sql', 'BEGIN TRANSACTION; COMMIT;');
        $zip->close();

        $this->artisan('backup:verify-latest')->assertSuccessful();

        $this->assertDatabaseHas('backup_verifications', ['path' => $path, 'status' => 'verified']);
    }

    public function test_restore_verification_is_scheduled_weekly(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($scheduledEvent): bool => str_contains((string) $scheduledEvent->command, 'backup:verify-latest'));

        $this->assertNotNull($event);
        $this->assertSame('0 3 * * 0', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
