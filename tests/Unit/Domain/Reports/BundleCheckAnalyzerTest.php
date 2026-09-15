<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Orders\OrderTypeClassifier;
use App\Domain\Reports\BundleCheckAnalyzer;
use Tests\TestCase;

class BundleCheckAnalyzerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['order-types' => [
            'fallback' => 'Other',
            'rules' => [[
                'name' => 'Z1', 'match' => 'sku_starts_with', 'value' => 'z1-',
                'exclude_if' => [['match' => 'title_contains', 'value' => 'warranty']],
                'required_items' => [['label' => 'Cap', 'match' => 'title_contains', 'value' => 'cap'], ['label' => 'Burrs', 'match' => 'sku_starts_with', 'value' => ['burr-', 'ssp-']]],
            ]],
        ]]);
    }

    public function test_it_flags_incomplete_fulfilled_bundles_and_applies_skip_rules(): void
    {
        $analyzer = new BundleCheckAnalyzer(new OrderTypeClassifier);
        $incomplete = $this->order('#NEW', [['sku' => 'z1-main', 'title' => 'Grinder'], ['sku' => 'burr-x', 'title' => 'Burrs']], ['fulfillment_status' => 'fulfilled']);
        $complete = $this->order('#COMPLETE', [['sku' => 'z1-main', 'title' => 'Grinder'], ['title' => 'Cap'], ['sku' => 'ssp-x']]);
        $cancelled = $this->order('#CANCELLED', $incomplete['line_items'], ['cancelled_at' => '2026-01-01']);
        $excluded = $this->order('#WARRANTY', [['sku' => 'z1-main', 'title' => 'Warranty']]);

        $rows = $analyzer->analyze([$complete, $cancelled, $excluded, $incomplete]);

        $this->assertCount(1, $rows);
        $this->assertSame('#NEW', $rows[0]['order_number']);
        $this->assertSame('Cap', $rows[0]['missing_text']);
        $this->assertSame('Z1', $rows[0]['order_type']);
    }

    private function order(string $name, array $items, array $overrides = []): array
    {
        return $overrides + ['id' => 1, 'name' => $name, 'created_at' => '2026-06-01', 'financial_status' => 'paid', 'fulfillment_status' => null, 'cancelled_at' => null, 'total_price' => 99, 'shipping_lines' => [['title' => 'Standard']], 'line_items' => $items];
    }
}
