<?php

namespace Tests\Feature\Reports;

use App\Application\Reports\ReportResult;
use App\Models\ReportRun;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportResultPersistenceTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('results')]
    public function test_saved_results_round_trip_as_encrypted_json(array $rows, int $scanned, array $params, array $meta): void
    {
        $run = $this->createRun();
        $result = new ReportResult($rows, $scanned, 3, true, $params, $meta);

        $run->storeResult($result);

        $fresh = $run->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->finished_at);
        $this->assertSame($result->toArray(), $fresh->result()->toArray());
        $raw = $fresh->getRawOriginal('result');
        $this->assertStringNotContainsString('customer@example.com', $raw);
        $json = gzuncompress(base64_decode(Crypt::decryptString($raw), true));
        $this->assertSame($result->toArray(), json_decode($json, true, flags: JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('result', $fresh->toArray());
    }

    public static function results(): array
    {
        return [
            'empty catalogue' => [[], 0, [], []],
            'date range with input filters' => [[['email' => 'customer@example.com']], 10, ['startDate' => '2026-06-01', 'minimum' => 25.0], ['critical' => 1, 'warnings' => 0]],
            'inventory with two sources' => [[['sku' => 'ABC']], 12, ['startDate' => '2026-06-01'], ['products' => 4, 'orders' => 12, 'productPages' => 2, 'orderPages' => 3, 'ordersTruncated' => true]],
            'summaries and nested rows' => [[['orders' => [['name' => '#1']]]], 20, ['threshold' => 14], ['bySku' => [['sku' => 'ABC', 'count' => 1]], 'byType' => [], 'revenue' => 0.0]],
            'unicode and nullable summaries' => [[['name' => 'Поръчка ✓']], 1, ['currency' => null], ['customer' => null, 'tags' => ['vip' => 2]]],
        ];
    }

    #[DataProvider('unavailableStatuses')]
    public function test_pending_or_failed_runs_do_not_expose_results(string $status): void
    {
        $run = $this->createRun();
        $run->storeResult(new ReportResult([['email' => 'customer@example.com']], 1));
        $run->forceFill(['status' => $status])->save();

        $this->assertNull($run->fresh()->result());
    }

    public static function unavailableStatuses(): array
    {
        return [['queued'], ['running'], ['failed']];
    }

    public function test_upgrade_converts_legacy_results_without_losing_inputs_rows_or_summary_counters(): void
    {
        $migration = require glob(database_path('migrations/*standardize_report_run_results.php'))[0];
        $migration->down();
        $run = $this->createRun();
        $legacy = (object) ['startDate' => '2026-06-01', 'endDate' => '2026-06-30', 'scanned' => 8, 'pairs' => [['first' => ['name' => '#1'], 'second' => ['name' => '#2']]], 'pages' => 2, 'truncated' => true, 'revenue' => 0.0];
        DB::table('report_runs')->where('id', $run->id)->update([
            'status' => 'completed',
            'rows_metric' => 'count:pairs',
            'result' => Crypt::encryptString(base64_encode(gzcompress(str_replace('O:8:"stdClass"', 'O:'.strlen('App\\Application\\Reports\\DuplicateOrderResult').':"App\\Application\\Reports\\DuplicateOrderResult"', serialize($legacy))))),
        ]);

        $migration->up();

        $result = $run->fresh()->result();
        $this->assertSame($legacy->pairs, $result->rows);
        $this->assertSame(8, $result->scanned);
        $this->assertSame(2, $result->pages);
        $this->assertTrue($result->truncated);
        $this->assertSame(['startDate' => '2026-06-01', 'endDate' => '2026-06-30'], $result->params);
        $this->assertSame(['revenue' => 0.0], $result->meta);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_upgrade_preserves_new_json_results_and_count_based_legacy_scans(): void
    {
        $migration = require glob(database_path('migrations/*standardize_report_run_results.php'))[0];
        $modern = $this->createRun();
        $modern->storeResult(new ReportResult([['name' => '#1']], 4));
        $encrypted = $modern->fresh()->getRawOriginal('result');
        $migration->down();
        $legacy = $this->createRun();
        DB::table('report_runs')->where('id', $legacy->id)->update([
            'status' => 'completed',
            'scanned_metric' => 'count:rows',
            'result' => Crypt::encryptString(base64_encode(gzcompress(serialize((object) ['rows' => [['name' => '#2']], 'startDate' => '2026-06-01'])))),
        ]);

        $migration->up();

        $this->assertSame($encrypted, $modern->fresh()->getRawOriginal('result'));
        $this->assertSame(1, $legacy->fresh()->result()->scanned);
        $this->assertSame([['name' => '#2']], $legacy->fresh()->result()->rows);
    }

    private function createRun(): ReportRun
    {
        [, $store] = $this->userWithStore(true);

        return $store->reportRuns()->create(['tool' => 'fraud_risk', 'report' => 'unused', 'arguments' => [], 'arguments_hash' => hash('sha256', 'test'), 'status' => 'queued']);
    }
}
