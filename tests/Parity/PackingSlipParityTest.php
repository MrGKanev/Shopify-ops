<?php

declare(strict_types=1);

use App\Domain\Orders\PackingSlipBuilder;
use PHPUnit\Framework\TestCase;

final class PackingSlipParityTest extends TestCase
{
    public function test_visible_packing_slip_data_matches_legacy(): void
    {
        $order = [
            'orderNumber' => '1001', 'orderDate' => '2026-09-01T10:00:00Z', 'shipByDate' => '2026-09-03T10:00:00Z', 'customerUsername' => 'buyer',
            'shipTo' => ['name' => 'Ada', 'street1' => '1 Main', 'city' => 'Sofia', 'country' => 'BG'],
            'items' => [['name' => 'Shirt', 'quantity' => 2, 'options' => [
                ['name' => 'Size', 'value' => '["S","M"]'],
                ['name' => 'GPO Options', 'value' => 'secret'],
            ]]],
            'internalNotes' => 'First<br/>Second', 'customerNotes' => 'Third', 'advancedOptions' => ['customField1' => 'Fourth'],
        ];
        $legacy = $this->legacyValues($order);
        $laravel = (new PackingSlipBuilder)->build($order);

        $this->assertSame($legacy['dates'], [$laravel['orderDate'], $laravel['shipByDate']]);
        $this->assertSame($legacy['notes'], $laravel['notes']);
        $this->assertSame($legacy['options'], $laravel['items'][0]['options']);
    }

    /** @return array{dates: list<string>, notes: list<string>, options: list<array{name: string, value: string, highlighted: bool}>} */
    private function legacyValues(array $order): array
    {
        $slipOrder = $order;
        $slipInput = '1001';
        $slipError = '';
        ob_start();
        require dirname(__DIR__, 2).'/views/packingslip.php';
        ob_end_clean();

        $options = [];
        foreach ($items[0]['options'] as $option) {
            if ($isHidden($option['name'])) {
                continue;
            }
            $highlighted = $isJsonArr($option['value']);
            $options[] = ['name' => $option['name'], 'value' => $highlighted ? $cleanVal($option['value']) : $option['value'], 'highlighted' => $highlighted];
        }

        return ['dates' => [$fmtDate($date), $fmtDate($shipBy)], 'notes' => $allNotes, 'options' => $options];
    }
}
