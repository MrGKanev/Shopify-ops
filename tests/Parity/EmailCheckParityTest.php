<?php

declare(strict_types=1);

use App\Domain\Reports\EmailCheckAnalyzer;
use PHPUnit\Framework\TestCase;

final class EmailCheckParityTest extends TestCase
{
    /** Flags a missing/invalid/disposable/placeholder-looking order email, each with its own severity. */
    public function test_rows_match_legacy(): void
    {
        $orders = [
            $this->order('1', ''), // no email -> critical
            $this->order('2', 'not-an-email'), // invalid format -> critical
            $this->order('3', 'buyer@mailinator.com'), // disposable domain -> critical
            $this->order('4', 'ab@example.com'), // short local part -> warning
            $this->order('5', 'noreply@example.com'), // placeholder pattern -> warning
            $this->order('6', 'aaaaaa@example.com'), // repeated chars -> warning
            $this->order('7', 'real.person@example.com'), // clean -> no row
        ];

        $legacyMethod = new ReflectionMethod(\SimpleScanPageLoader::class, 'buildEmailCheckRows');
        $legacyRows = $legacyMethod->invoke(null, $orders);

        $laravelRows = (new EmailCheckAnalyzer())->analyze($orders);

        $this->assertSame($this->summarize($legacyRows), $this->summarize($laravelRows));
    }

    /** @return array<string, mixed> */
    private function order(string $orderNumber, string $email): array
    {
        return ['id' => $orderNumber, 'name' => "#{$orderNumber}", 'email' => $email, 'created_at' => '2026-01-01T00:00:00Z'];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{order_number: mixed, severity: mixed, messages: mixed}>
     */
    private function summarize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'order_number' => $r['order_number'] ?? $r['number'],
            'severity' => $r['severity'],
            'messages' => array_column($r['issues'], 'message'),
        ], $rows);
    }
}
