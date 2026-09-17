<?php

namespace Tests\Feature\Admin;

use App\Models\BackupVerification;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class BackupVerificationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_an_administrator_can_verify_a_listed_backup(): void
    {
        Storage::fake('backups');
        $path = $this->createValidBackup();
        $this->post(route('admin.backups.verify'), ['path' => $path])->assertRedirect(route('login'));
        [$operator] = $this->makeUserAndStore(false);

        $this->actingAs($operator)->post(route('admin.backups.verify'), ['path' => $path])->assertForbidden();
    }

    public function test_an_administrator_can_verify_a_backup_and_see_its_status(): void
    {
        Storage::fake('backups');
        $path = $this->createValidBackup();
        [$admin] = $this->makeUserAndStore(true);

        $this->actingAs($admin)->post(route('admin.backups.verify'), ['path' => $path])
            ->assertRedirect()
            ->assertSessionHas('status', 'Backup-ът е проверен успешно и съдържа четим database dump.');

        $verification = BackupVerification::query()->sole();
        $this->assertSame('verified', $verification->status);
        $this->actingAs($admin)->get(route('admin.backups.index'))
            ->assertOk()
            ->assertSeeText('Verified');
    }

    public function test_it_rejects_a_path_outside_the_backup_listing(): void
    {
        Storage::fake('backups');
        [$admin] = $this->makeUserAndStore(true);

        $this->actingAs($admin)->post(route('admin.backups.verify'), ['path' => '../.env'])
            ->assertNotFound();

        $this->assertSame(0, BackupVerification::query()->count());
    }

    private function createValidBackup(): string
    {
        $folder = (string) config('backup.backup.name');
        $path = "{$folder}/valid.zip";
        $absolutePath = Storage::disk('backups')->path($path);
        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }
        $zip = new ZipArchive;
        $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('db-dumps/sqlite-sqlite-database.sql', "BEGIN TRANSACTION;\nCREATE TABLE stores (id integer);\nCOMMIT;");
        $zip->close();

        return $path;
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
