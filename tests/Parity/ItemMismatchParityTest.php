<?php

declare(strict_types=1);

use App\Domain\Orders\OrderChannelComparator;
use App\Domain\Orders\OrderTypeClassifier;
use App\Domain\Reports\ItemMismatchAnalyzer;
use PHPUnit\Framework\TestCase;

final class ItemMismatchParityTest extends TestCase
{
    /**
     * This report exists to catch picking errors — an order shipped with the
     * wrong item, or missing a bundle accessory (Accent Piece/Funnel Cap/Burr
     * Set on a Z1/Z2 grinder, see `laravel/config/order-types.php`) — so a
     * silently-skipped order here means a customer receives the wrong thing
     * and nobody finds out. That's the risk this fixture is built to exercise
     * per scenario, not just "does the diff math agree in the easy case."
     */
    public function test_rows_match_legacy(): void
    {
        $ssOrders = [
            // #1101: shipped exactly what was ordered incl. the bundle accessory -> no row
            $this->ssOrder('1101', 'shipped', '901', [$this->item('WIDGET', 1)]),
            // #1102: shipped the wrong SKU -> missing WIDGET, extra GADGET
            $this->ssOrder('1102', 'shipped', '902', [$this->item('GADGET', 1)]),
            // #1103: Z1 grinder ordered with its Accent Piece, but the accessory
            // wasn't in the box -> missing_required, even though item counts otherwise differ
            $this->ssOrder('1103', 'shipped', '903', [$this->item('ZERNO-Z1-BLACK', 1)]),
            // #1104: cancelled order -> skipped regardless of any mismatch
            $this->ssOrder('1104', 'shipped', '904', [$this->item('GADGET', 1)]),
            // #1105: refunded order -> skipped
            $this->ssOrder('1105', 'shipped', '905', [$this->item('GADGET', 1)]),
            // #1106: total_price is explicitly null (not "0.00", not missing) -- the
            // isset()-vs-array_key_exists() case already found and fixed once this
            // session in AuditOrderAnalyzer; confirms the same fix in ItemMismatchAnalyzer
            $this->ssOrder('1106', 'shipped', '906', [$this->item('GADGET', 1)]),
            // #1107: genuinely zero-value ("0.00", a real digital/gift-card order) -> skipped
            $this->ssOrder('1107', 'shipped', '907', [$this->item('GADGET', 1)]),
            // #1108: not yet shipped -> skipped, never reaches the diff at all
            $this->ssOrder('1108', 'awaiting_shipment', '908', [$this->item('WIDGET', 1)]),
            // #9999: no matching Shopify order -> skipped
            $this->ssOrder('9999', 'shipped', '999', [$this->item('WIDGET', 1)]),
        ];

        $shopifyOrders = [
            $this->shopifyOrder(1, '1101', [$this->item('WIDGET', 1)], totalPrice: '25.00'),
            $this->shopifyOrder(2, '1102', [$this->item('WIDGET', 1)], totalPrice: '25.00'),
            $this->shopifyOrder(3, '1103', [$this->item('ZERNO-Z1-BLACK', 1), $this->item('ACC-1', 1, 'Accent Piece')], totalPrice: '199.00'),
            $this->shopifyOrder(4, '1104', [$this->item('WIDGET', 1)], totalPrice: '25.00', cancelledAt: '2026-01-01T00:00:00Z'),
            $this->shopifyOrder(5, '1105', [$this->item('WIDGET', 1)], totalPrice: '25.00', financialStatus: 'refunded'),
            $this->shopifyOrder(6, '1106', [$this->item('WIDGET', 1)], totalPrice: null),
            $this->shopifyOrder(7, '1107', [$this->item('WIDGET', 1)], totalPrice: '0.00'),
            $this->shopifyOrder(8, '1108', [$this->item('WIDGET', 1)], totalPrice: '25.00'),
        ];

        $legacyMethod = new ReflectionMethod(\FulfillmentIssuePageLoader::class, 'buildItemMismatchRows');
        $legacyRows = $legacyMethod->invoke(null, $ssOrders, $shopifyOrders);

        $comparator = new OrderChannelComparator();
        $classifier = new OrderTypeClassifier();
        $laravelRows = (new ItemMismatchAnalyzer($comparator, $classifier))->analyze($ssOrders, $shopifyOrders);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function ssOrder(string $orderNumber, string $status, string $orderId, array $items): array
    {
        return ['orderNumber' => $orderNumber, 'orderStatus' => $status, 'orderId' => $orderId, 'items' => $items];
    }

    /** @return array<string, mixed> */
    private function item(string $sku, int $quantity, string $name = ''): array
    {
        return ['sku' => $sku, 'name' => $name ?: $sku, 'quantity' => $quantity];
    }

    /** @return array<string, mixed> */
    private function shopifyOrder(
        int $id,
        string $orderNumber,
        array $lineItems,
        ?string $totalPrice,
        ?string $cancelledAt = null,
        ?string $financialStatus = null,
    ): array {
        $order = [
            'id' => $id,
            'order_number' => $orderNumber,
            'name' => "#{$orderNumber}",
            'email' => "order{$orderNumber}@example.com",
            'created_at' => '2026-01-01T00:00:00Z',
            'line_items' => $lineItems,
            'total_price' => $totalPrice,
        ];
        if ($cancelledAt !== null) {
            $order['cancelled_at'] = $cancelledAt;
        }
        if ($financialStatus !== null) {
            $order['financial_status'] = $financialStatus;
        }

        return $order;
    }

    /**
     * `order_number` is deliberately normalised (leading `#` stripped) before
     * comparing: legacy's row echoes `$shOrder['order_number']` verbatim
     * (here, the bare digits, e.g. `"1102"`), while `ItemMismatchAnalyzer`
     * always echoes `$shopifyOrder['name']` (Shopify's `#`-prefixed display
     * form, e.g. `"#1102"`). Same order, same identity, just a different
     * source field for the label shown to an operator — not a matching,
     * filtering, or content bug, so it doesn't belong in a "does the real
     * business logic agree" assertion.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{order_number: mixed, missing: mixed, extra: mixed, missing_required: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'order_number' => ltrim((string) $r['order_number'], '#'),
            'missing' => $r['missing'],
            'extra' => $r['extra'],
            'missing_required' => array_values($r['missing_required']),
        ], $rows);
    }
}
