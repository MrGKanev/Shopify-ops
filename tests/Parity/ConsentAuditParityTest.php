<?php

declare(strict_types=1);

use App\Domain\Reports\ConsentAuditAnalyzer;
use PHPUnit\Framework\TestCase;

final class ConsentAuditParityTest extends TestCase
{
    /**
     * Flags orders whose customer isn't subscribed to email marketing -- a
     * compliance risk if that customer is later targeted with a campaign.
     * `customer_email_consent` reaches this function already lowercased by
     * `OrderNormalizer` on both sides, so the fixture matches that real
     * contract rather than testing raw-case GraphQL enum values.
     */
    public function test_rows_match_legacy(): void
    {
        $orders = [
            $this->order('1', 'subscribed'), // subscribed -> not flagged
            $this->order('2', 'not_subscribed'), // flagged
            $this->order('3', 'pending'), // flagged
            $this->order('4', ''), // missing entirely -> flagged, shown as 'unknown'
        ];

        $legacyMethod = new ReflectionMethod(\OrderPolicyPageLoader::class, 'buildConsentAuditRows');
        $legacyRows = $legacyMethod->invoke(null, $orders);

        $laravelRows = (new ConsentAuditAnalyzer())->analyze($orders);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function order(string $orderNumber, string $emailConsent): array
    {
        return ['id' => $orderNumber, 'name' => "#{$orderNumber}", 'email' => "c{$orderNumber}@example.com", 'created_at' => '2026-01-01T00:00:00Z', 'customer_email_consent' => $emailConsent, 'financial_status' => 'paid', 'total_price' => '50.00'];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{order_number: mixed, email_consent: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'order_number' => $r['order_number'] ?? $r['number'],
            'email_consent' => $r['email_consent'],
        ], $rows);
    }
}
