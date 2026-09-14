<?php

declare(strict_types=1);

use App\Domain\Reports\AuditOrderAnalyzer;
use PHPUnit\Framework\TestCase;

final class AuditComparisonParityTest extends TestCase
{
    public function test_classification_matches_legacy_across_every_skip_reason_and_match_path(): void
    {
        $shopifyOrders = [
            $this->order(1, '#100', '100', 'a@x.com', '10.00'),
            $this->order(2, '#101', '101', 'a@x.com', '10.00', cancelledAt: '2026-01-01T00:00:00Z'),
            $this->order(3, '#102', '102', 'a@x.com', '10.00', financialStatus: 'pending'),
            $this->order(4, '#103', '103', 'a@x.com', '10.00', financialStatus: 'voided'),
            $this->order(5, '#104', '104', 'a@x.com', '10.00', financialStatus: 'refunded'),
            $this->order(6, '#105', '105', 'a@x.com', '10.00', financialStatus: 'partially_refunded'),
            $this->order(7, '#106', '106', 'a@x.com', '10.00', fulfillmentStatus: 'fulfilled'),
            $this->order(8, '#107', '107', 'a@x.com', '10.00', fulfillmentStatus: 'restocked'),
            $this->order(9, '#108', '108', 'a@x.com', '0.00'),
            $this->order(10, '#109', '109', 'a@x.com', '10.00', shippingLines: []),
            $this->order(11, '#110', '110', 'a@x.com', '10.00'),
            $this->order(12, '#111', '999999', 'a@x.com', '10.00'),
            $this->order(13, '#112', '999998', 'match@x.com', '20.00'),
            $this->order(14, '#113', '999997', 'almost@x.com', '20.00'),
            $this->order(15, '#114', '997', 'z@x.com', '10.00'),
            $this->order(16, '#115', '996', 'z@x.com', '10.00'),
            $this->order(17, '#100042', '100042', 'zz@x.com', '10.00'),
            $this->order(18, '#116', '995', 'zz@x.com', null),
        ];

        $ssOrders = [
            $this->ssOrder('110', 'a@x.com', 10.00),
            $this->ssOrder('111', 'a@x.com', 10.00),
            $this->ssOrder('555555', 'match@x.com', 20.05),
            $this->ssOrder('444444', 'almost@x.com', 25.00),
            $this->ssOrder('100042-B2', 'zz@x.com', 999.00),
        ];

        $ignored = ['100' => ['reason' => 'test', 'ignored_at' => '2026-01-01']];
        $onHoldIds = ['15'];

        $this->assertSame(
            $this->legacyClassify($shopifyOrders, $ssOrders, $ignored, $onHoldIds),
            $this->laravelClassify($shopifyOrders, $ssOrders, $ignored, $onHoldIds),
        );
    }

    /** @return array<string, mixed> */
    private function order(
        int $id,
        string $name,
        ?string $orderNumber,
        string $email,
        ?string $totalPrice,
        ?string $cancelledAt = null,
        ?string $financialStatus = null,
        ?string $fulfillmentStatus = null,
        ?array $shippingLines = null,
    ): array {
        $order = ['id' => $id, 'name' => $name, 'email' => $email, 'total_price' => $totalPrice];
        if ($orderNumber !== null) {
            $order['order_number'] = $orderNumber;
        }
        if ($cancelledAt !== null) {
            $order['cancelled_at'] = $cancelledAt;
        }
        if ($financialStatus !== null) {
            $order['financial_status'] = $financialStatus;
        }
        if ($fulfillmentStatus !== null) {
            $order['fulfillment_status'] = $fulfillmentStatus;
        }
        if ($shippingLines !== null) {
            $order['shipping_lines'] = $shippingLines;
        }

        return $order;
    }

    /** @return array<string, mixed> */
    private function ssOrder(string $orderNumber, string $email, float $orderTotal): array
    {
        return ['orderNumber' => $orderNumber, 'customerEmail' => $email, 'orderTotal' => $orderTotal];
    }

    /**
     * @param list<array<string, mixed>> $shopifyOrders
     * @param list<array<string, mixed>> $ssOrders
     * @param array<string, mixed> $ignored
     * @param list<string> $onHoldIds
     * @return array<string, array{bucket: string, skip_reason: ?string, match_method: ?string}>
     */
    private function legacyClassify(array $shopifyOrders, array $ssOrders, array $ignored, array $onHoldIds): array
    {
        $ssIndex = \Comparator::buildSSIndex($ssOrders);
        $ssEmailIndex = \Comparator::buildSSEmailIndex($ssOrders);
        $result = \Comparator::compare($shopifyOrders, $ssIndex, $ignored, $ssEmailIndex);
        $result = \Comparator::applyOnHoldSkip(
            $result,
            static fn (array $order): bool => in_array((string) ($order['id'] ?? ''), $onHoldIds, true),
        );

        return $this->summarize($result, '_skip_reason', '_match_method');
    }

    /**
     * @param list<array<string, mixed>> $shopifyOrders
     * @param list<array<string, mixed>> $ssOrders
     * @param array<string, mixed> $ignored
     * @param list<string> $onHoldIds
     * @return array<string, array{bucket: string, skip_reason: ?string, match_method: ?string}>
     */
    private function laravelClassify(array $shopifyOrders, array $ssOrders, array $ignored, array $onHoldIds): array
    {
        $onHoldMap = array_fill_keys($onHoldIds, true);
        $result = (new AuditOrderAnalyzer())->analyze($shopifyOrders, $ssOrders, $ignored, $onHoldMap);

        return $this->summarize($result, 'skip_reason', 'match_method');
    }

    /**
     * @param array{missing: list<array<string, mixed>>, found: list<array<string, mixed>>, skipped: list<array<string, mixed>>, ignored: list<array<string, mixed>>} $result
     * @return array<string, array{bucket: string, skip_reason: ?string, match_method: ?string}>
     */
    private function summarize(array $result, string $skipKey, string $matchKey): array
    {
        $out = [];
        foreach (['missing', 'found', 'skipped', 'ignored'] as $bucket) {
            foreach ($result[$bucket] as $order) {
                $out[$order['name']] = [
                    'bucket' => $bucket,
                    'skip_reason' => $order[$skipKey] ?? null,
                    'match_method' => $order[$matchKey] ?? null,
                ];
            }
        }
        ksort($out);

        return $out;
    }
}
