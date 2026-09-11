<?php

namespace App\Domain\Reports;

class AuditOrderAnalyzer
{
    /** Shopify's order-number sequence is 4+ digits; shorter digit runs inside a compound ShipStation number (e.g. the "2" in "100042-B2") are box/addon suffixes, not real order numbers on their own. */
    private const int MIN_FRAGMENT_LENGTH = 4;

    /** @param list<array<string,mixed>> $shopify @param list<array<string,mixed>> $shipstation @param array<string,array<string,mixed>> $ignored @return array{missing:list<array<string,mixed>>,found:list<array<string,mixed>>,skipped:list<array<string,mixed>>,ignored:list<array<string,mixed>>} */
    public function analyze(array $shopify, array $shipstation, array $ignored, array $onHoldOrderIds = []): array
    {
        $byNumber = $byEmail = [];
        foreach ($shipstation as $order) {
            foreach ($this->numberKeys((string) ($order['orderNumber'] ?? '')) as $key) {
                $byNumber[$key][] = $order;
            }
            $email = mb_strtolower(trim((string) ($order['customerEmail'] ?? '')));
            if ($email !== '') {
                $byEmail[$email][] = $order;
            }
        }
        $result = ['missing' => [], 'found' => [], 'skipped' => [], 'ignored' => []];
        foreach ($shopify as $order) {
            $number = $this->number($order['order_number'] ?? $order['name'] ?? '');
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
            $name = $this->number($order['name'] ?? '');
            $matches = $byNumber[$number] ?? $byNumber[$name] ?? null;
            if ($matches !== null) {
                $order['shipstation_matches'] = $matches;
                $order['match_method'] = 'order_number';
                $result['found'][] = $order;

                continue;
            }
            $email = mb_strtolower(trim((string) ($order['email'] ?? '')));
            $total = (float) ($order['total_price'] ?? 0);
            foreach ($byEmail[$email] ?? [] as $candidate) {
                if ($total > 0 && abs($total - (float) ($candidate['orderTotal'] ?? 0)) / $total < 0.01) {
                    $order['shipstation_matches'] = [$candidate];
                    $order['match_method'] = 'email+amount';
                    $result['found'][] = $order;

                    continue 2;
                }
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
        if (array_key_exists('total_price', $order) && (float) $order['total_price'] === 0.0) {
            return 'zero_value';
        }
        if (array_key_exists('shipping_lines', $order) && $order['shipping_lines'] === []) {
            return 'no_shipping';
        }

        return null;
    }

    private function number(mixed $value): string
    {
        return preg_replace('/\D+/', '', is_scalar($value) ? (string) $value : '') ?? '';
    }

    /** All lookup keys a raw ShipStation order number resolves to: the full digits-only form, plus each individual digit run long enough to plausibly be a standalone order number. @return list<string> */
    private function numberKeys(string $raw): array
    {
        $keys = [];
        $full = $this->number($raw);
        if ($full !== '') {
            $keys[] = $full;
        }
        preg_match_all('/\d+/', $raw, $matches);
        foreach ($matches[0] as $segment) {
            if ($segment !== $full && strlen($segment) >= self::MIN_FRAGMENT_LENGTH) {
                $keys[] = $segment;
            }
        }

        return $keys;
    }
}
