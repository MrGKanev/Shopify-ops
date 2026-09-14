<?php

declare(strict_types=1);

use App\Domain\Reports\GiftCardsAnalyzer;
use PHPUnit\Framework\TestCase;

final class GiftCardsParityTest extends TestCase
{
    /**
     * Flags gift-card liabilities worth an operator's attention: cards
     * about to expire (or already expired) with money still on them, and
     * cards nobody has ever used. Both reasons can apply to the same card,
     * so the fixture checks each condition, their combination, and the
     * disabled/zero-balance exclusions.
     */
    public function test_rows_match_legacy(): void
    {
        $now = time();
        $days = 30;

        $giftCards = [
            // expiring in 10 days, already used some -> 'Expiring in 10d' only
            $this->card('1', true, 50.0, 100.0, $now + 10 * 86400),
            // expired 5 days ago -> 'Expired'
            $this->card('2', true, 20.0, 20.0 - 0.01, $now - 5 * 86400),
            // never redeemed, expires far in the future -> 'Never redeemed' only
            $this->card('3', true, 75.0, 75.0, $now + 365 * 86400),
            // never redeemed AND expiring soon -> both reasons
            $this->card('4', true, 40.0, 40.0, $now + 1 * 86400),
            // disabled -> excluded regardless of balance/expiry
            $this->card('5', false, 30.0, 30.0, $now + 1 * 86400),
            // zero balance -> excluded
            $this->card('6', true, 0.0, 100.0, $now + 1 * 86400),
            // no expiry date, already partially used, not never-redeemed -> no reasons, excluded
            $this->card('7', true, 50.0, 100.0, null),
        ];

        $legacyMethod = new ReflectionMethod(\GiftCardPageLoader::class, 'buildGiftCardRows');
        $legacyRows = $legacyMethod->invoke(null, $giftCards, $days, $now);

        $laravelRows = (new GiftCardsAnalyzer())->analyze($giftCards, $days, $now);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function card(string $id, bool $enabled, float $balance, float $initialValue, ?int $expiresAtTs): array
    {
        return [
            'id' => $id,
            'masked_code' => "****{$id}",
            'enabled' => $enabled,
            'balance' => $balance,
            'initial_value' => $initialValue,
            'currency' => 'USD',
            'expires_on' => $expiresAtTs === null ? '' : gmdate('Y-m-d\TH:i:s\Z', $expiresAtTs),
            'created_at' => '2026-01-01T00:00:00Z',
            'customer_email' => "c{$id}@example.com",
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: mixed, days_until_expiry: mixed, reasons: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'days_until_expiry' => $r['days_until_expiry'],
            'reasons' => $r['reasons'],
        ], $rows);
    }
}
