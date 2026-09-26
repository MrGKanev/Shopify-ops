<?php

namespace Tests\Feature\Application\Backups;

use App\Application\Backups\RestoreBackupArchive;
use App\Application\Backups\VerifyBackupArchive;
use App\Jobs\RestoreBackup;
use App\Models\BackupVerification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class RestoreBackupJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/backup-operations'));

        parent::tearDown();
    }

    public function test_it_verifies_a_safety_backup_before_restoring_and_records_the_result_outside_the_database(): void
    {
        Storage::fake('backups');
        $folder = (string) config('backup.backup.name');
        $path = $folder.'/restore-target.zip';
        $safetyPath = $folder.'/safety-backup.zip';
        Storage::disk('backups')->put($path, 'target');
        $expectedChecksum = hash('sha256', 'target');
        $verified = BackupVerification::factory()->make(['status' => 'verified', 'checksum' => $expectedChecksum]);
        $safetyVerification = BackupVerification::factory()->make(['status' => 'verified', 'checksum' => hash('sha256', 'safety')]);

        $verifier = Mockery::mock(VerifyBackupArchive::class);
        $verifier->shouldReceive('handle')->once()->with($path)->andReturn($verified);
        $verifier->shouldReceive('handle')->once()->with($safetyPath)->andReturn($safetyVerification);
        $this->app->instance(VerifyBackupArchive::class, $verifier);

        $restorer = Mockery::mock(RestoreBackupArchive::class);
        $restorer->shouldReceive('handle')->once()->with($path, $expectedChecksum)
            ->andReturn(['database_dump' => 'db-dumps/database.sql', 'restored_files' => 2]);
        $this->app->instance(RestoreBackupArchive::class, $restorer);

        Artisan::shouldReceive('call')->once()->with('backup:run', [
            '--isolated' => true,
            '--disable-notifications' => true,
        ])->andReturnUsing(function () use ($safetyPath): int {
            Storage::disk('backups')->put($safetyPath, 'safety');

            return 0;
        });
        Artisan::shouldReceive('call')->once()->with('down', ['--retry' => 60])->andReturn(0);
        Artisan::shouldReceive('call')->once()->with('up')->andReturn(0);

        File::ensureDirectoryExists(storage_path('app/backup-operations'), 0700);
        File::put(storage_path('app/backup-operations/test-operation.json'), json_encode([
            'id' => 'test-operation',
            'path' => $path,
            'status' => 'queued',
            'requested_by' => 'Admin',
            'requested_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        (new RestoreBackup('test-operation', $path, $expectedChecksum, 'Admin'))->handle($verifier, $restorer);

        $operation = json_decode(File::get(storage_path('app/backup-operations/test-operation.json')), true);
        $this->assertSame('completed', $operation['status']);
        $this->assertSame($safetyPath, $operation['safety_backup']);
        $this->assertSame(2, $operation['result']['restored_files']);
    }

    public function test_it_does_not_restore_if_the_safety_backup_cannot_be_verified(): void
    {
        Storage::fake('backups');
        $folder = (string) config('backup.backup.name');
        $path = $folder.'/restore-target.zip';
        $safetyPath = $folder.'/safety-backup.zip';
        Storage::disk('backups')->put($path, 'target');
        $expectedChecksum = hash('sha256', 'target');
        $verified = BackupVerification::factory()->make(['status' => 'verified', 'checksum' => $expectedChecksum]);
        $failedVerification = BackupVerification::factory()->make(['status' => 'failed', 'checksum' => null]);

        $verifier = Mockery::mock(VerifyBackupArchive::class);
        $verifier->shouldReceive('handle')->once()->with($path)->andReturn($verified);
        $verifier->shouldReceive('handle')->once()->with($safetyPath)->andReturn($failedVerification);
        $this->app->instance(VerifyBackupArchive::class, $verifier);

        $restorer = Mockery::mock(RestoreBackupArchive::class);
        $restorer->shouldNotReceive('handle');
        $this->app->instance(RestoreBackupArchive::class, $restorer);
        Artisan::shouldReceive('call')->once()->andReturnUsing(function () use ($safetyPath): int {
            Storage::disk('backups')->put($safetyPath, 'safety');

            return 0;
        });

        File::ensureDirectoryExists(storage_path('app/backup-operations'), 0700);
        File::put(storage_path('app/backup-operations/test-operation.json'), json_encode([
            'id' => 'test-operation',
            'path' => $path,
            'status' => 'queued',
            'requested_by' => 'Admin',
            'requested_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        (new RestoreBackup('test-operation', $path, $expectedChecksum, 'Admin'))->handle($verifier, $restorer);

        $operation = json_decode(File::get(storage_path('app/backup-operations/test-operation.json')), true);
        $this->assertSame('failed', $operation['status']);
        $this->assertSame('The pre-restore safety backup failed verification.', $operation['error']);
    }
}
