<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class IgnoreListTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ignorelist_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        IgnoreList::setDataDir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
    }

    // ── load ──────────────────────────────────────────────────────────────────

    public function testLoadReturnsEmptyArrayWhenNoFile(): void
    {
        $this->assertSame([], IgnoreList::load());
    }

    // ── add / remove ──────────────────────────────────────────────────────────

    public function testAddCreatesEntry(): void
    {
        IgnoreList::add('12345', 'test reason');
        $data = IgnoreList::load();

        $this->assertArrayHasKey('12345', $data);
        $this->assertSame('test reason', $data['12345']['reason']);
    }

    public function testAddSetsIgnoredAtDate(): void
    {
        IgnoreList::add('12345', 'any');
        $data = IgnoreList::load();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['12345']['ignored_at']);
    }

    public function testAddEmptyNumberIsNoOp(): void
    {
        IgnoreList::add('', 'test');
        $this->assertSame([], IgnoreList::load());
    }

    public function testRemoveDeletesEntry(): void
    {
        IgnoreList::add('12345', 'test');
        IgnoreList::remove('12345');

        $this->assertArrayNotHasKey('12345', IgnoreList::load());
    }

    public function testRemoveNonExistentEntryIsNoOp(): void
    {
        IgnoreList::add('11111', 'keep');
        IgnoreList::remove('99999');

        $this->assertArrayHasKey('11111', IgnoreList::load());
    }

    public function testRemoveEmptyNumberIsNoOp(): void
    {
        IgnoreList::add('11111', 'keep');
        IgnoreList::remove('');

        $this->assertArrayHasKey('11111', IgnoreList::load());
    }

    public function testAddOverwritesExistingEntry(): void
    {
        IgnoreList::add('12345', 'first reason');
        IgnoreList::add('12345', 'updated reason');

        $this->assertSame('updated reason', IgnoreList::load()['12345']['reason']);
    }

    // ── bulkAdd / bulkRemove ──────────────────────────────────────────────────

    public function testBulkAddCreatesMultipleEntries(): void
    {
        IgnoreList::bulkAdd([
            ['number' => '11111', 'reason' => 'reason A'],
            ['number' => '22222', 'reason' => 'reason B'],
        ]);
        $data = IgnoreList::load();

        $this->assertArrayHasKey('11111', $data);
        $this->assertArrayHasKey('22222', $data);
        $this->assertSame('reason A', $data['11111']['reason']);
    }

    public function testBulkAddSkipsEntriesWithEmptyNumber(): void
    {
        IgnoreList::bulkAdd([
            ['number' => '',      'reason' => 'skipped'],
            ['number' => '33333', 'reason' => 'valid'],
        ]);
        $data = IgnoreList::load();

        $this->assertCount(1, $data);
        $this->assertArrayHasKey('33333', $data);
    }

    public function testBulkRemoveDeletesSelectedEntries(): void
    {
        IgnoreList::bulkAdd([
            ['number' => '11111', 'reason' => 'a'],
            ['number' => '22222', 'reason' => 'b'],
            ['number' => '33333', 'reason' => 'c'],
        ]);
        IgnoreList::bulkRemove(['11111', '33333']);
        $data = IgnoreList::load();

        $this->assertArrayNotHasKey('11111', $data);
        $this->assertArrayNotHasKey('33333', $data);
        $this->assertArrayHasKey('22222', $data);
    }

    public function testBulkRemoveWithNonExistentNumbersIsNoOp(): void
    {
        IgnoreList::add('12345', 'keep');
        IgnoreList::bulkRemove(['99999', '88888']);

        $this->assertArrayHasKey('12345', IgnoreList::load());
    }

    // ── importCsv ─────────────────────────────────────────────────────────────

    public function testImportCsvSkipsNonNumericHeader(): void
    {
        $csv = $this->tmpDir . '/import.csv';
        file_put_contents($csv, "order_number\n12345\n67890\n");

        $count = IgnoreList::importCsv($csv, 'bulk import');
        $data  = IgnoreList::load();

        $this->assertSame(2, $count);
        $this->assertArrayHasKey('12345', $data);
        $this->assertArrayHasKey('67890', $data);
    }

    public function testImportCsvIncludesNumericFirstRow(): void
    {
        $csv = $this->tmpDir . '/import.csv';
        file_put_contents($csv, "12345\n67890\n");

        $count = IgnoreList::importCsv($csv, 'bulk import');

        $this->assertSame(2, $count);
    }

    public function testImportCsvStripsHashPrefix(): void
    {
        $csv = $this->tmpDir . '/import.csv';
        file_put_contents($csv, "#12345\n#67890\n");

        IgnoreList::importCsv($csv, 'bulk');
        $data = IgnoreList::load();

        $this->assertArrayHasKey('12345', $data);
        $this->assertArrayHasKey('67890', $data);
    }

    public function testImportCsvSetsReason(): void
    {
        $csv = $this->tmpDir . '/import.csv';
        file_put_contents($csv, "12345\n");

        IgnoreList::importCsv($csv, 'my reason');

        $this->assertSame('my reason', IgnoreList::load()['12345']['reason']);
    }
}
