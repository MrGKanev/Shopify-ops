<?php

namespace Tests\Feature\Application\Backups;

use App\Application\Backups\RestoreBackupArchive;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class RestoreBackupArchiveTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_restores_the_database_dump_and_application_files(): void
    {
        User::factory()->create(['email' => 'before-restore@example.com']);
        $this->assertSame(1, User::query()->count());

        Storage::fake('backups');
        Storage::fake('local');
        $dump = "DELETE FROM users;\nINSERT INTO users (id, name, email, password, role, created_at, updated_at) VALUES (99, 'Restored Admin', 'restored@example.com', 'hash', 'admin', '2026-01-01 00:00:00', '2026-01-01 00:00:00');";
        $path = $this->createZip('valid.zip', [
            'db-dumps/sqlite-database.sql' => $dump,
            'storage/app/private/reports/example.csv' => 'id,total\n1,2',
        ]);

        $result = app(RestoreBackupArchive::class)->handle($path);

        $this->assertSame('db-dumps/sqlite-database.sql', $result['database_dump']);
        $this->assertSame(1, $result['restored_files']);
        $this->assertSame(1, User::query()->count());
        $this->assertDatabaseHas('users', ['email' => 'restored@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'before-restore@example.com']);
        Storage::disk('local')->assertExists('reports/example.csv');
    }

    public function test_it_skips_archive_entries_that_attempt_path_traversal(): void
    {
        Storage::fake('backups');
        Storage::fake('local');
        $path = $this->createZip('traversal.zip', [
            'db-dumps/sqlite-database.sql' => 'SELECT 1;',
            'storage/app/private/../../../etc/passwd' => 'malicious',
            'storage/app/private/reports/safe.csv' => 'id,total',
        ]);

        $result = app(RestoreBackupArchive::class)->handle($path);

        $this->assertSame(1, $result['restored_files']);
        Storage::disk('local')->assertExists('reports/safe.csv');
    }

    public function test_it_restores_a_mysql_dump_through_the_mysql_client(): void
    {
        Process::fake();
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => 'db.internal',
            'database.connections.mysql.port' => '3306',
            'database.connections.mysql.username' => 'ops',
            'database.connections.mysql.password' => 'super-secret',
            'database.connections.mysql.database' => 'shopify_ops',
        ]);
        Storage::fake('backups');
        $path = $this->createZip('mysql.zip', ['db-dumps/mysql-shopify_ops.sql' => 'INSERT INTO users VALUES (1);']);

        $result = app(RestoreBackupArchive::class)->handle($path);

        $this->assertSame('db-dumps/mysql-shopify_ops.sql', $result['database_dump']);
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['mysql', '--host=db.internal', '--port=3306', '--user=ops', 'shopify_ops']
            && $process->environment === ['MYSQL_PWD' => 'super-secret']
            && $process->input === 'INSERT INTO users VALUES (1);');
    }

    public function test_it_fails_when_the_mysql_client_exits_with_an_error(): void
    {
        Process::fake(['*mysql*' => Process::result(errorOutput: 'Access denied', exitCode: 1)]);
        config(['database.default' => 'mysql']);
        Storage::fake('backups');
        $path = $this->createZip('mysql-failure.zip', ['db-dumps/mysql-shopify_ops.sql' => 'INSERT INTO users VALUES (1);']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The mysql client failed to restore the dump: Access denied');

        app(RestoreBackupArchive::class)->handle($path);
    }

    public function test_it_rejects_an_unsupported_database_driver(): void
    {
        config(['database.default' => 'pgsql']);
        Storage::fake('backups');
        $path = $this->createZip('pgsql.zip', ['db-dumps/pgsql-shopify_ops.sql' => 'INSERT INTO users VALUES (1);']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Restoring the 'pgsql' driver is not supported.");

        app(RestoreBackupArchive::class)->handle($path);
    }

    public function test_it_rejects_an_archive_without_a_database_dump(): void
    {
        Storage::fake('backups');
        $path = $this->createZip('without-dump.zip', ['storage/app/private/reports/example.csv' => 'contents']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No database dump was found in the backup archive.');

        app(RestoreBackupArchive::class)->handle($path);
    }

    public function test_it_rejects_a_missing_archive(): void
    {
        Storage::fake('backups');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The backup archive no longer exists.');

        app(RestoreBackupArchive::class)->handle((string) config('backup.backup.name').'/missing.zip');
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
