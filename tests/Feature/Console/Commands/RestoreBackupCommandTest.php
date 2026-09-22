<?php

namespace Tests\Feature\Console\Commands;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class RestoreBackupCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_restores_the_latest_archive_after_confirmation(): void
    {
        User::factory()->create(['email' => 'before-restore@example.com']);
        Storage::fake('backups');
        Storage::fake('local');
        $path = $this->createZip('valid.zip', [
            'db-dumps/sqlite-database.sql' => "DELETE FROM users;\nINSERT INTO users (id, name, email, password, role, created_at, updated_at) VALUES (1, 'Restored', 'restored@example.com', 'hash', 'admin', '2026-01-01 00:00:00', '2026-01-01 00:00:00');",
        ]);

        $this->artisan('backup:restore')
            ->expectsConfirmation("This overwrites the current database and stored files with the contents of {$path}. Continue?", 'yes')
            ->expectsOutputToContain('Restored db-dumps/sqlite-database.sql')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'restored@example.com']);
    }

    public function test_it_cancels_without_confirmation(): void
    {
        Storage::fake('backups');
        $path = $this->createZip('valid.zip', ['db-dumps/sqlite-database.sql' => 'DELETE FROM users;']);

        $this->artisan('backup:restore')
            ->expectsConfirmation("This overwrites the current database and stored files with the contents of {$path}. Continue?", 'no')
            ->expectsOutputToContain('Restore cancelled.')
            ->assertFailed();
    }

    public function test_it_fails_when_no_backup_archive_exists(): void
    {
        Storage::fake('backups');

        $this->artisan('backup:restore', ['--force' => true])
            ->expectsOutputToContain('No backup archive is available to restore.')
            ->assertFailed();
    }

    public function test_force_skips_the_confirmation_prompt(): void
    {
        User::factory()->create(['email' => 'before-restore@example.com']);
        Storage::fake('backups');
        Storage::fake('local');
        $this->createZip('valid.zip', [
            'db-dumps/sqlite-database.sql' => "DELETE FROM users;\nINSERT INTO users (id, name, email, password, role, created_at, updated_at) VALUES (1, 'Restored', 'restored@example.com', 'hash', 'admin', '2026-01-01 00:00:00', '2026-01-01 00:00:00');",
        ]);

        $this->artisan('backup:restore', ['--force' => true])
            ->doesntExpectOutputToContain('Continue?')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'restored@example.com']);
    }

    public function test_it_restores_an_explicitly_named_archive_instead_of_the_latest(): void
    {
        $this->assertDatabaseCount('users', 0);
        Storage::fake('backups');
        Storage::fake('local');
        $this->createZip('older.zip', ['db-dumps/sqlite-database.sql' => 'DELETE FROM users;']);
        $chosenPath = $this->createZip('newer.zip', [
            'db-dumps/sqlite-database.sql' => "INSERT INTO users (id, name, email, password, role, created_at, updated_at) VALUES (1, 'Newer', 'newer@example.com', 'hash', 'admin', '2026-01-01 00:00:00', '2026-01-01 00:00:00');",
        ]);

        $this->artisan('backup:restore', ['path' => $chosenPath, '--force' => true])
            ->expectsOutputToContain("Restored db-dumps/sqlite-database.sql and 0 file(s) from {$chosenPath}.")
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'newer@example.com']);
    }

    /** @param array<string, string> $entries */
    private function createZip(string $name, array $entries): string
    {
        $folder = (string) config('backup.backup.name');
        $path = "{$folder}/{$name}";
        $absolutePath = Storage::disk('backups')->path($path);
        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $entry => $contents) {
            $this->assertTrue($zip->addFromString($entry, $contents));
        }
        $this->assertTrue($zip->close());

        return $path;
    }
}
