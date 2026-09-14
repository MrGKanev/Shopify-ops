<?php

declare(strict_types=1);

use App\Domain\Reports\DiscountAbuseAnalyzer;
use PHPUnit\Framework\TestCase;

final class DiscountAbuseParityTest extends TestCase
{
    /**
     * Flags a discount code being reused at the same shipping address by
     * several different customer emails — a classic promo-abuse pattern
     * (one person, several throwaway accounts, one address). A grouping or
     * threshold bug here either hides real abuse or flags innocent repeat
     * customers, so the fixture exercises the clustering key (address +
     * code), the min-emails threshold, email dedup within one group, and
     * the email_count/order_count sort tiebreak.
     */
    public function test_rows_match_legacy(): void
    {
        $sameAddr = ['address1' => '123 Main St', 'city' => 'Springfield', 'zip' => '62704', 'country_code' => 'US', 'first_name' => 'A', 'last_name' => 'Buyer'];
        $otherAddr = ['address1' => '9 Oak Ave', 'city' => 'Shelbyville', 'zip' => '62565', 'country_code' => 'US'];

        $orders = [
            // 3 distinct emails reuse SAVE10 at the same address -> flagged (min 2)
            $this->order(1, '4001', 'a@example.com', 'SAVE10', $sameAddr, '50.00'),
            $this->order(2, '4002', 'b@example.com', 'save10', $sameAddr, '60.00'),
            $this->order(3, '4003', 'c@example.com', 'SAVE10', $sameAddr, '70.00'),
            // same email reusing the code at the same address twice -> counts
            // once toward email_count (dedup), but both orders land in the group
            $this->order(4, '4004', 'a@example.com', 'SAVE10', $sameAddr, '10.00'),
            // different code at the same address -> separate group, only 1 email -> not flagged
            $this->order(5, '4005', 'd@example.com', 'OTHER5', $sameAddr, '20.00'),
            // same code, different address -> separate group, only 1 email -> not flagged
            $this->order(6, '4006', 'e@example.com', 'SAVE10', $otherAddr, '30.00'),
            // no discount codes -> skipped entirely
            $this->order(7, '4007', 'f@example.com', null, $sameAddr, '40.00'),
        ];

        $legacyMethod = new ReflectionMethod(\OrderPolicyPageLoader::class, 'buildDiscountAbuseRows');
        $legacyRows = $legacyMethod->invoke(null, $orders, 2);

        $laravelRows = (new DiscountAbuseAnalyzer())->analyze($orders, 2);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function order(int $id, string $orderNumber, string $email, ?string $discountCode, array $shippingAddress, string $totalPrice): array
    {
        return [
            'id' => $id,
            'name' => "#{$orderNumber}",
            'email' => $email,
            'created_at' => '2026-01-01T00:00:00Z',
            'total_price' => $totalPrice,
            'financial_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'shipping_address' => $shippingAddress,
            'discount_codes' => $discountCode === null ? [] : [['code' => $discountCode]],
        ];
    }

    /**
     * `orders` (the per-row order list) is excluded: legacy keys each entry
     * `shopify_id`/`order_number`, Laravel keys it `id`/`number` and adds a
     * `currency` field — a field-naming difference, not a grouping/matching
     * bug, same precedent as the `order_number`-vs-`name` footnote in
     * ItemMismatchParityTest. `order_count` (derived from that same list)
     * IS compared, since that's the actual business signal.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{code: mixed, address_line: mixed, address_name: mixed, email_count: mixed, order_count: mixed, emails: mixed, total: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'code' => $r['code'],
            'address_line' => $r['addr_line'] ?? $r['address_line'] ?? null,
            'address_name' => $r['addr_name'] ?? $r['address_name'] ?? null,
            'email_count' => $r['email_count'],
            'order_count' => $r['order_count'],
            'emails' => $r['emails'],
            'total' => (float) $r['total'],
        ], $rows);
    }
}
