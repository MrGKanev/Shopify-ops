<?php

namespace App\Domain\Orders;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Classifies the customer of one open order from their observed order history. Incomplete history never yields "first".
 */
class CustomerFulfillmentContext
{
    public const RULES = ['all', 'first', 'repeat', 'vip', 'milestone', 'sku_repeat', 'unknown'];

    public const MILESTONES = [5, 10, 25];

    /**
     * @param  list<array{name: string, created_at: string, cancelled: bool, test: bool, customer_id: string, skus: list<string>, lines_complete: bool}>  $history
     * @param  list<string>  $currentSkus
     * @param  list<int>|null  $milestones  Order numbers below the VIP count that deserve attention; defaults to MILESTONES.
     * @return array{status: string, priorOrders: int, atLeast: bool, orderNumber: int|null, tier: string|null, repeatedSkus: list<string>, skuHistoryComplete: bool, reasons: list<string>}
     */
    public function classify(string $email, string $orderName, array $history, bool $truncated, array $currentSkus, int $vipAt = 5, ?array $milestones = null): array
    {
        $unknown = fn (string $reason): array => ['status' => 'unknown', 'priorOrders' => 0, 'atLeast' => false, 'orderNumber' => null, 'tier' => null, 'repeatedSkus' => [], 'skuHistoryComplete' => false, 'reasons' => [$reason]];
        if (trim($email) === '') {
            return $unknown('guest_without_email');
        }
        $key = fn (string $name): string => ltrim(trim($name), '#');
        $current = null;
        foreach ($history as $order) {
            if ($key($order['name']) === $key($orderName)) {
                $current = $order;
                break;
            }
        }
        $currentAt = $current === null ? null : $this->parse($current['created_at']);
        if ($current === null || $currentAt === null) {
            return $unknown('current_order_not_in_history');
        }

        $prior = [];
        $unplaceable = false;
        foreach ($history as $order) {
            if ($key($order['name']) === $key($orderName) || $order['cancelled'] || $order['test']) {
                continue;
            }
            if ($current['customer_id'] !== '' && $order['customer_id'] !== '' && $order['customer_id'] !== $current['customer_id']) {
                continue;
            }
            $at = $this->parse($order['created_at']);
            if ($at === null) {
                $unplaceable = true;

                continue;
            }
            if ($at->lessThan($currentAt)) {
                $prior[] = $order;
            }
        }

        $incomplete = $truncated || $unplaceable;
        $count = count($prior);
        if ($count === 0 && $incomplete) {
            return $unknown($truncated ? 'history_truncated' : 'history_has_undated_orders');
        }

        $wanted = array_values(array_unique(array_map(fn (string $sku): string => mb_strtolower(trim($sku)), array_filter($currentSkus, fn (string $sku): bool => trim($sku) !== ''))));
        $repeated = [];
        $skuComplete = ! $incomplete;
        foreach ($prior as $order) {
            $skuComplete = $skuComplete && $order['lines_complete'];
            $repeated = [...$repeated, ...array_intersect($wanted, $order['skus'])];
        }
        $repeated = array_values(array_unique($repeated));
        $position = $count + 1;
        $tier = $position >= $vipAt ? 'vip' : (in_array($position, $milestones ?? self::MILESTONES, true) ? 'milestone' : null);
        $reasons = [$count === 0 ? 'no_prior_orders' : 'has_prior_orders'];
        if ($tier !== null) {
            $reasons[] = $tier === 'vip' ? 'vip_threshold' : 'milestone_order';
        }
        if ($repeated !== []) {
            $reasons[] = 'sku_bought_before';
        }
        if ($incomplete) {
            $reasons[] = 'history_incomplete';
        }

        return ['status' => $count === 0 ? 'first' : 'repeat', 'priorOrders' => $count, 'atLeast' => $incomplete, 'orderNumber' => $position, 'tier' => $tier, 'repeatedSkus' => $repeated, 'skuHistoryComplete' => $skuComplete, 'reasons' => $reasons];
    }

    /** @param array{status: string, tier: string|null, repeatedSkus: list<string>} $context */
    public function matches(array $context, string $rule): bool
    {
        return match ($rule) {
            'first' => $context['status'] === 'first',
            'repeat' => $context['status'] === 'repeat',
            'vip' => $context['tier'] === 'vip',
            'milestone' => $context['tier'] === 'milestone',
            'sku_repeat' => $context['repeatedSkus'] !== [],
            'unknown' => $context['status'] === 'unknown',
            default => true,
        };
    }

    private function parse(string $value): ?CarbonImmutable
    {
        try {
            return trim($value) === '' ? null : CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
