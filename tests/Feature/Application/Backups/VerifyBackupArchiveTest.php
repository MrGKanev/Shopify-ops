<?php

namespace Tests\Feature\Application\Backups;

use App\Application\Backups\VerifyBackupArchive;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class VerifyBackupArchiveTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_streams_and_verifies_every_entry_and_the_database_dump(): void
    {
        Storage::fake('backups');
        $path = $this->createZip('valid.zip', [
            'db-dumps/sqlite-sqlite-database.sql' => "BEGIN TRANSACTION;\nCREATE TABLE users (id integer);\nCOMMIT;",
            'files/readme.txt' => 'backup file',
        ]);

        $verification = app(VerifyBackupArchive::class)->handle($path);

        $this->assertSame('verified', $verification->status);
        $this->assertSame(2, $verification->entries);
        $this->assertSame('db-dumps/sqlite-sqlite-database.sql', $verification->database_dump);
        $this->assertSame(64, strlen((string) $verification->checksum));
        $this->assertNull($verification->error);
    }

    public function test_it_records_corrupt_and_incomplete_archives_as_failed(): void
    {
        Storage::fake('backups');
        $folder = (string) config('backup.backup.name');
        Storage::disk('backups')->put("{$folder}/corrupt.zip", 'not-a-zip');
        $withoutDump = $this->createZip('without-dump.zip', ['files/readme.txt' => 'backup file']);

        $corrupt = app(VerifyBackupArchive::class)->handle("{$folder}/corrupt.zip");
        $incomplete = app(VerifyBackupArchive::class)->handle($withoutDump);

        $this->assertSame('failed', $corrupt->status);
        $this->assertStringContainsString('ZIP integrity check failed', (string) $corrupt->error);
        $this->assertSame('failed', $incomplete->status);
        $this->assertSame('No database dump was found in the backup archive.', $incomplete->error);
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
