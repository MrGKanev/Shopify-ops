<?php

namespace App\Domain\Reports;

class AuditOrderAnalyzer
{
    /** @param list<array<string,mixed>> $shopify @param list<array<string,mixed>> $shipstation @param array<string,array<string,mixed>> $ignored @return array{missing:list<array<string,mixed>>,found:list<array<string,mixed>>,skipped:list<array<string,mixed>>,ignored:list<array<string,mixed>>} */
    public function analyze(array $shopify, array $shipstation, array $ignored, array $onHoldOrderIds = []): array
    {
        $byNumber = $byEmail = [];
        foreach ($shipstation as $order) {
            $number = $this->number($order['orderNumber'] ?? '');
            if ($number !== '') {
                $byNumber[$number][] = $order;
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
}
