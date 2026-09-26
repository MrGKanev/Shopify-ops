<?php

namespace Tests\Feature\Admin;

use App\Jobs\RestoreBackup;
use App\Models\BackupVerification;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupRestoreControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/backup-operations'));

        parent::tearDown();
    }

    public function test_only_an_administrator_can_start_a_restore(): void
    {
        Storage::fake('backups');
        $path = $this->backupPath();
        Storage::disk('backups')->put($path, 'archive');
        BackupVerification::factory()->create(['path' => $path]);
        [$operator] = $this->makeUserAndStore(false);

        $this->actingAs($operator)->post(route('admin.backups.restore'), [
            'path' => $path,
            'confirmation' => basename($path),
        ])->assertForbidden();

        $operationDirectory = storage_path('app/backup-operations');
        $this->assertSame([], File::isDirectory($operationDirectory) ? File::files($operationDirectory) : []);
    }

    public function test_restore_requires_a_verified_archive_and_exact_name_confirmation(): void
    {
        Storage::fake('backups');
        $path = $this->backupPath();
        Storage::disk('backups')->put($path, 'archive');
        [$admin] = $this->makeUserAndStore(true);

        $this->actingAs($admin)->post(route('admin.backups.restore'), [
            'path' => $path,
            'confirmation' => basename($path),
        ])->assertSessionHasErrors('restore');

        BackupVerification::factory()->create(['path' => $path]);
        $this->actingAs($admin)->post(route('admin.backups.restore'), [
            'path' => $path,
            'confirmation' => 'wrong.zip',
        ])->assertSessionHasErrors('confirmation');
    }

    public function test_admin_can_queue_restore_of_a_verified_archive_in_a_background_process(): void
    {
        Storage::fake('backups');
        $path = $this->backupPath();
        Storage::disk('backups')->put($path, 'archive');
        $verification = BackupVerification::factory()->create(['path' => $path]);
        [$admin] = $this->makeUserAndStore(true);
        Queue::fake();

        $this->actingAs($admin)->post(route('admin.backups.restore'), [
            'path' => $path,
            'confirmation' => basename($path),
        ])->assertSessionHas('status');

        Queue::assertPushed(RestoreBackup::class, fn (RestoreBackup $job): bool => $job->path === $path
            && $job->expectedChecksum === $verification->checksum
            && $job->connection === 'background');

        $operations = File::files(storage_path('app/backup-operations'));
        $this->assertCount(1, $operations);
        $operation = json_decode(File::get($operations[0]->getPathname()), true);
        $this->assertSame('queued', $operation['status']);
        $this->assertSame($path, $operation['path']);
    }

    private function backupPath(): string
    {
        return (string) config('backup.backup.name').'/old-backup.zip';
    }

    /** @return array{User, Store} */
    private function makeUserAndStore(bool $administrator): array
    {
        $user = $administrator ? User::factory()->admin()->create() : User::factory()->operator()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
