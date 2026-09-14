<?php

declare(strict_types=1);

use App\Domain\Orders\OrderTypeClassifier;
use App\Domain\Reports\BundleCheckAnalyzer;
use PHPUnit\Framework\TestCase;

final class BundleCheckParityTest extends TestCase
{
    /**
     * This report exists to catch a specific admin-process failure: a Z1/Z2
     * grinder's required accessories (Accent Piece, Funnel Cap, Burr Set,
     * see `laravel/config/order-types.php`) are added to an order by an
     * admin after checkout, and the bundle check is what catches it when
     * that step was missed. A silently-skipped row here means a customer
     * gets an incomplete grinder and nobody finds out — so the fixture
     * exercises the actual rule engine (required items, exclude_if,
     * fulfilled-orders-still-flagged) rather than just the happy path.
     */
    public function test_rows_match_legacy(): void
    {
        $orders = [
            // #2001: Z1 missing Accent Piece and Funnel Cap, has Burr Set
            $this->order(1, '2001', [
                $this->item('ZERNO-Z1-BLACK'),
                $this->item('SSP-40', 'Burr Set'),
            ]),
            // #2002: Z1 with all three required items -> not flagged
            $this->order(2, '2002', [
                $this->item('ZERNO-Z1-SILVER'),
                $this->item('ACC-1', 'Accent Piece'),
                $this->item('FUN-1', 'Funnel Cap'),
                $this->item('CORE-1', 'Burr Set'),
            ]),
            // #2003: Z2 missing everything, but a warranty line item triggers
            // exclude_if -> not flagged despite the missing accessories
            $this->order(3, '2003', [
                $this->item('ZERNO-Z2-BLACK'),
                $this->item('WARR-1', 'Extended warranty'),
            ]),
            // #2004: plain non-bundle order -> classified 'Addons' fallback, no rule to check
            $this->order(4, '2004', [$this->item('WIDGET', 'Widget')]),
            // #2005: Z1 missing Accent Piece, but cancelled -> skipped
            $this->order(5, '2005', [$this->item('ZERNO-Z1-BLACK')], cancelledAt: '2026-01-01T00:00:00Z'),
            // #2006: Z1 missing Accent Piece, total_price explicitly null -> skipped
            // as zero-value (the isset()-vs-array_key_exists() bug pattern found
            // repeatedly this session; here both sides use `?? 0`, so this fixture
            // asserts that stays true rather than assuming it)
            $this->order(6, '2006', [$this->item('ZERNO-Z1-BLACK')], totalPrice: null),
            // #2007: Z1 missing Accent Piece, no shipping lines -> skipped
            $this->order(7, '2007', [$this->item('ZERNO-Z1-BLACK')], shippingLines: []),
            // #2008: Z1 missing Accent Piece, fulfilled -> still flagged (bundle
            // check deliberately does NOT exclude fulfilled orders, per
            // ProductInventoryPageLoader::buildBundleCheckRows()'s docblock)
            $this->order(8, '2008', [$this->item('ZERNO-Z1-BLACK')], fulfillmentStatus: 'fulfilled'),
            // #2009: Z1 missing Accent Piece, refunded -> skipped
            $this->order(9, '2009', [$this->item('ZERNO-Z1-BLACK')], financialStatus: 'refunded'),
        ];

        $legacyMethod = new ReflectionMethod(\ProductInventoryPageLoader::class, 'buildBundleCheckRows');
        $legacyRows = $legacyMethod->invoke(null, $orders);

        $laravelRows = (new BundleCheckAnalyzer(new OrderTypeClassifier()))->analyze($orders);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function item(string $sku, string $title = ''): array
    {
        return ['sku' => $sku, 'title' => $title ?: $sku, 'vendor' => ''];
    }

    /** @return array<string, mixed> */
    private function order(
        int $id,
        string $orderNumber,
        array $lineItems,
        ?string $totalPrice = '199.00',
        ?string $cancelledAt = null,
        ?string $financialStatus = null,
        ?string $fulfillmentStatus = null,
        ?array $shippingLines = null,
    ): array {
        $order = [
            'id' => $id,
            'name' => "#{$orderNumber}",
            'email' => "order{$orderNumber}@example.com",
            'created_at' => '2026-01-01T00:00:00Z',
            'line_items' => $lineItems,
            'total_price' => $totalPrice,
            'shipping_lines' => $shippingLines ?? [['title' => 'Standard']],
        ];
        if ($cancelledAt !== null) {
            $order['cancelled_at'] = $cancelledAt;
        }
        if ($financialStatus !== null) {
            $order['financial_status'] = $financialStatus;
        }
        if ($fulfillmentStatus !== null) {
            $order['fulfillment_status'] = $fulfillmentStatus;
        }

        return $order;
    }

    /**
     * `total` is deliberately excluded: legacy echoes the raw `total_price`
     * field verbatim (string, e.g. `"199.00"`), while `BundleCheckAnalyzer`
     * casts it to float (`199.0`). Same value, different PHP type — a
     * display-shape difference, not a matching/filtering/content bug, so it
     * doesn't belong in a "does the real business logic agree" assertion
     * (same precedent as the `total`/`ss_url` footnotes in
     * ShippingMarginParityTest / ItemMismatchParityTest).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{order_number: mixed, order_type: mixed, missing_required: mixed, missing_text: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'order_number' => $r['order_number'],
            'order_type' => $r['order_type'],
            'missing_required' => $r['missing_required'],
            'missing_text' => $r['missing_text'],
        ], $rows);
    }
}
