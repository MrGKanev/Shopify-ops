<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ComparatorTest extends TestCase
{
    // ── normalise ─────────────────────────────────────────────────────────────

    public function testNormaliseStripsHash(): void
    {
        $this->assertSame('12345', Comparator::normalise('#12345'));
    }

    public function testNormaliseStripsWhitespace(): void
    {
        $this->assertSame('12345', Comparator::normalise('  #12345  '));
    }

    public function testNormaliseKeepsOnlyDigits(): void
    {
        $this->assertSame('12345', Comparator::normalise('ORDER-12345'));
    }

    public function testNormaliseJoinsMultipleSegments(): void
    {
        // "100042-B2" → digits only joined → "1000422"
        $this->assertSame('1000422', Comparator::normalise('100042-B2'));
    }

    public function testNormaliseEmptyString(): void
    {
        $this->assertSame('', Comparator::normalise(''));
    }

    // ── buildSSIndex ──────────────────────────────────────────────────────────

    public function testBuildSSIndexPrimaryKey(): void
    {
        $orders = [['orderNumber' => '#12345', 'orderId' => 1]];
        $index  = Comparator::buildSSIndex($orders);

        $this->assertArrayHasKey('12345', $index);
        $this->assertCount(1, $index['12345']);
    }

    public function testBuildSSIndexCompoundNumber(): void
    {
        $orders = [['orderNumber' => '100042-B2', 'orderId' => 1]];
        $index  = Comparator::buildSSIndex($orders);

        // Full normalised key
        $this->assertArrayHasKey('1000422', $index);
        // Individual segment keys
        $this->assertArrayHasKey('100042', $index);
        $this->assertArrayHasKey('2', $index);
    }

    public function testBuildSSEmailIndex(): void
    {
        $orders = [
            ['orderNumber' => '1', 'customerEmail' => 'Alice@Example.com'],
            ['orderNumber' => '2', 'customerEmail' => 'alice@example.com'],
        ];
        $index = Comparator::buildSSEmailIndex($orders);

        $this->assertArrayHasKey('alice@example.com', $index);
        $this->assertCount(2, $index['alice@example.com']);
    }

    // ── compare ───────────────────────────────────────────────────────────────

    private function makeShopifyOrder(array $overrides = []): array
    {
        return array_merge([
            'id'                 => 1,
            'order_number'       => 65001,
            'name'               => '#165001',
            'financial_status'   => 'paid',
            'fulfillment_status' => null,
            'cancelled_at'       => null,
            'total_price'        => '99.00',
            'email'              => 'test@example.com',
            'shipping_lines'     => [['title' => 'Standard Shipping']],
        ], $overrides);
    }

    public function testCompareFoundByOrderNumber(): void
    {
        $order   = $this->makeShopifyOrder();
        $ssIndex = ['65001' => [['orderNumber' => '65001']]];

        $result = Comparator::compare([$order], $ssIndex);

        $this->assertCount(1, $result['found']);
        $this->assertCount(0, $result['missing']);
        $this->assertSame('order_number', $result['found'][0]['_match_method']);
    }

    public function testCompareMissingOrder(): void
    {
        $order  = $this->makeShopifyOrder();
        $result = Comparator::compare([$order], []);

        $this->assertCount(1, $result['missing']);
        $this->assertCount(0, $result['found']);
    }

    public function testCompareSkipsCancelled(): void
    {
        $order  = $this->makeShopifyOrder(['cancelled_at' => '2024-01-01T10:00:00Z']);
        $result = Comparator::compare([$order], []);

        $this->assertCount(1, $result['skipped']);
        $this->assertSame('cancelled', $result['skipped'][0]['_skip_reason']);
    }

    public function testCompareSkipsPendingFinancial(): void
    {
        $order  = $this->makeShopifyOrder(['financial_status' => 'pending']);
        $result = Comparator::compare([$order], []);

        $this->assertCount(1, $result['skipped']);
        $this->assertSame('financial', $result['skipped'][0]['_skip_reason']);
    }

    public function testCompareSkipsRefunded(): void
    {
        $order  = $this->makeShopifyOrder(['financial_status' => 'refunded']);
        $result = Comparator::compare([$order], []);

        $this->assertSame('financial', $result['skipped'][0]['_skip_reason']);
    }

    public function testCompareSkipsFulfilled(): void
    {
        $order  = $this->makeShopifyOrder(['fulfillment_status' => 'fulfilled']);
        $result = Comparator::compare([$order], []);

        $this->assertSame('fulfilled', $result['skipped'][0]['_skip_reason']);
    }

    public function testCompareSkipsRestocked(): void
    {
        $order  = $this->makeShopifyOrder(['fulfillment_status' => 'restocked']);
        $result = Comparator::compare([$order], []);

        $this->assertSame('fulfilled', $result['skipped'][0]['_skip_reason']);
    }

    public function testCompareSkipsZeroValue(): void
    {
        $order  = $this->makeShopifyOrder(['total_price' => '0.00']);
        $result = Comparator::compare([$order], []);

        $this->assertSame('zero_value', $result['skipped'][0]['_skip_reason']);
    }

    public function testCompareSkipsNoShipping(): void
    {
        $order  = $this->makeShopifyOrder(['shipping_lines' => []]);
        $result = Comparator::compare([$order], []);

        $this->assertSame('no_shipping', $result['skipped'][0]['_skip_reason']);
    }

    public function testCompareIgnoredOrder(): void
    {
        $order   = $this->makeShopifyOrder();
        $ignored = ['65001' => ['reason' => 'test']];
        $result  = Comparator::compare([$order], [], $ignored);

        $this->assertCount(1, $result['ignored']);
        $this->assertCount(0, $result['missing']);
    }

    public function testCompareEmailFallback(): void
    {
        $order       = $this->makeShopifyOrder(['total_price' => '100.00']);
        $ssEmailIndex = ['test@example.com' => [['orderTotal' => 100.00, 'orderNumber' => '99999']]];

        $result = Comparator::compare([$order], [], [], $ssEmailIndex);

        $this->assertCount(1, $result['found']);
        $this->assertSame('email+amount', $result['found'][0]['_match_method']);
    }

    public function testCompareEmailFallbackToleranceWithin1Percent(): void
    {
        // Shopify: $100.00, SS: $100.50 → 0.5% difference → should match
        $order        = $this->makeShopifyOrder(['total_price' => '100.00']);
        $ssEmailIndex = ['test@example.com' => [['orderTotal' => 100.50, 'orderNumber' => '99999']]];

        $result = Comparator::compare([$order], [], [], $ssEmailIndex);
        $this->assertCount(1, $result['found']);
    }

    public function testCompareEmailFallbackExceeds1Percent(): void
    {
        // Shopify: $100.00, SS: $102.00 → 2% difference → no match
        $order        = $this->makeShopifyOrder(['total_price' => '100.00']);
        $ssEmailIndex = ['test@example.com' => [['orderTotal' => 102.00, 'orderNumber' => '99999']]];

        $result = Comparator::compare([$order], [], [], $ssEmailIndex);
        $this->assertCount(1, $result['missing']);
    }

    // ── findDuplicates ────────────────────────────────────────────────────────

    private function makeOrder(string $email, string $total, string $createdAt): array
    {
        return [
            'email'       => $email,
            'total_price' => $total,
            'created_at'  => $createdAt,
            'order_number'=> rand(1000, 9999),
        ];
    }

    public function testFindDuplicatesWithinWindow(): void
    {
        $orders = [
            $this->makeOrder('a@b.com', '50.00', '2024-01-01T10:00:00Z'),
            $this->makeOrder('a@b.com', '50.00', '2024-01-01T10:30:00Z'),
        ];
        $result = Comparator::findDuplicates($orders);

        $this->assertCount(1, $result);
        $this->assertSame('a@b.com', $result[0]['email']);
        $this->assertCount(2, $result[0]['orders']);
    }

    public function testFindDuplicatesOutsideWindow(): void
    {
        $orders = [
            $this->makeOrder('a@b.com', '50.00', '2024-01-01T10:00:00Z'),
            $this->makeOrder('a@b.com', '50.00', '2024-01-03T10:00:00Z'), // 48h apart
        ];
        $result = Comparator::findDuplicates($orders);

        $this->assertCount(0, $result);
    }

    public function testFindDuplicatesDifferentAmounts(): void
    {
        $orders = [
            $this->makeOrder('a@b.com', '50.00', '2024-01-01T10:00:00Z'),
            $this->makeOrder('a@b.com', '75.00', '2024-01-01T10:30:00Z'),
        ];
        $result = Comparator::findDuplicates($orders);

        $this->assertCount(0, $result);
    }

    public function testFindDuplicatesDifferentEmails(): void
    {
        $orders = [
            $this->makeOrder('a@b.com', '50.00', '2024-01-01T10:00:00Z'),
            $this->makeOrder('c@d.com', '50.00', '2024-01-01T10:30:00Z'),
        ];
        $result = Comparator::findDuplicates($orders);

        $this->assertCount(0, $result);
    }
}
