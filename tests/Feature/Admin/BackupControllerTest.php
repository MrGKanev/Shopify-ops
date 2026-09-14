<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_an_admin_can_view_or_download_backups(): void
    {
        Storage::fake('backups');
        $folder = (string) config('backup.backup.name');
        Storage::disk('backups')->put("{$folder}/2026-09-11-00-00-00.zip", 'zip-bytes');

        $this->get(route('admin.backups.index'))->assertRedirect(route('login'));
        [$operator] = $this->userWithStore(false);
        $this->actingAs($operator)->get(route('admin.backups.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('admin.backups.download', ["{$folder}/2026-09-11-00-00-00.zip"]))->assertForbidden();
    }

    public function test_admin_sees_backup_file_name_and_size(): void
    {
        Storage::fake('backups');
        $folder = (string) config('backup.backup.name');
        Storage::disk('backups')->put("{$folder}/2026-09-11-00-00-00.zip", 'zip-bytes');
        [$admin] = $this->userWithStore(true);

        $this->actingAs($admin)->get(route('admin.backups.index'))
            ->assertOk()
            ->assertSeeText('2026-09-11-00-00-00.zip');
    }

    public function test_admin_can_download_a_backup(): void
    {
        Storage::fake('backups');
        $folder = (string) config('backup.backup.name');
        Storage::disk('backups')->put("{$folder}/2026-09-11-00-00-00.zip", 'zip-bytes');
        [$admin] = $this->userWithStore(true);

        $response = $this->actingAs($admin)->get(route('admin.backups.download', ["{$folder}/2026-09-11-00-00-00.zip"]));

        $response->assertOk();
        $this->assertSame('zip-bytes', $response->streamedContent());
    }

    public function test_it_refuses_to_download_a_path_outside_the_backup_listing(): void
    {
        Storage::fake('backups');
        [$admin] = $this->userWithStore(true);

        $this->actingAs($admin)->get(route('admin.backups.download', ['../../.env']))->assertNotFound();
    }

    /** @return array{User, Store} */
    private function userWithStore(bool $admin): array
    {
        $user = $admin ? User::factory()->admin()->create() : User::factory()->operator()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store);

        return [$user, $store];
    }
}
