<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Concerns\MatchesOrderNumbers;

class AuditOrderAnalyzer
{
    use MatchesOrderNumbers;

    /** @param list<array<string,mixed>> $shopify @param list<array<string,mixed>> $shipstation @param array<string,array<string,mixed>> $ignored @return array{missing:list<array<string,mixed>>,found:list<array<string,mixed>>,skipped:list<array<string,mixed>>,ignored:list<array<string,mixed>>} */
    public function analyze(array $shopify, array $shipstation, array $ignored, array $onHoldOrderIds = []): array
    {
        $byNumber = $byEmail = [];
        foreach ($shipstation as $order) {
            foreach ($this->orderNumberKeys($order['orderNumber'] ?? '') as $key) {
                $byNumber[$key][] = $order;
            }
            $email = mb_strtolower(trim((string) ($order['customerEmail'] ?? '')));
            if ($email !== '') {
                $byEmail[$email][] = $order;
            }
        }
        $result = ['missing' => [], 'found' => [], 'skipped' => [], 'ignored' => []];
        foreach ($shopify as $order) {
            $number = $this->orderNumber($order['order_number'] ?? $order['name'] ?? '');
            if (isset($ignored[$number])) {
                $order['ignore'] = $ignored[$number];
                $result['ignored'][] = $order;

                continue;
            }
            $reason = $this->skipReason($order);
            if ($reason !== null) {
                $order['skip_reason'] = $reason;
                $result['skipped'][] = $order;

                continue;
            }
            $name = $this->orderNumber($order['name'] ?? '');
            $matches = $byNumber[$number] ?? $byNumber[$name] ?? null;
            if ($matches !== null) {
                $order['shipstation_matches'] = $matches;
                $order['match_method'] = 'order_number';
                $result['found'][] = $order;

                continue;
            }
            $email = mb_strtolower(trim((string) ($order['email'] ?? '')));
            $total = (float) ($order['total_price'] ?? 0);
            $closest = null;
            $closestDifference = null;
            foreach ($byEmail[$email] ?? [] as $candidate) {
                $difference = abs($total - (float) ($candidate['orderTotal'] ?? 0));
                if ($total > 0 && $difference / $total < 0.01 && ($closestDifference === null || $difference < $closestDifference)) {
                    $closest = $candidate;
                    $closestDifference = $difference;
                }
            }
            if ($closest !== null) {
                $order['shipstation_matches'] = [$closest];
                $order['match_method'] = 'email+amount';
                $result['found'][] = $order;

                continue;
            }
            if (isset($onHoldOrderIds[(string) ($order['id'] ?? '')])) {
                $order['skip_reason'] = 'on_hold';
                $result['skipped'][] = $order;

                continue;
            }
            $result['missing'][] = $order;
        }

        return $result;
    }

    private function skipReason(array $order): ?string
    {
        if ($order['cancelled_at'] ?? null) {
            return 'cancelled';
        }
        if (in_array($order['financial_status'] ?? '', ['pending', 'voided', 'refunded', 'partially_refunded'], true)) {
            return 'financial';
        }
        if (in_array($order['fulfillment_status'] ?? '', ['fulfilled', 'restocked'], true)) {
            return 'fulfilled';
        }
        if (isset($order['total_price']) && (float) $order['total_price'] === 0.0) {
            return 'zero_value';
        }
        if (array_key_exists('shipping_lines', $order) && $order['shipping_lines'] === []) {
            return 'no_shipping';
        }

        return null;
    }
}
