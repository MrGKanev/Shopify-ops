<?php

namespace App\Domain\Orders;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

class OrderConsolidationPlanner
{
    /**
     * Groups open orders that share a verified customer (email, recipient and full destination) and works out which of them could ship together.
     *
     * A shared address alone is never enough: every candidate must be awaiting shipment, match on ship-from, requested service and delivery confirmation, fall inside the waiting window, and carry no unreviewed paid-shipping, customs, insurance, gift or special-handling flag. Everything else stays visible with its reason. Nothing is merged and no order is delayed.
     *
     * @param  list<array{number: string, status: string, created_at: CarbonImmutable|null, email: string, recipient: string, destination: string|null, warehouse: string, service: string, confirmation: string, paid_shipping: bool, international: bool, insured: bool, gift: bool, special: bool, weight: array{value: float, units: string}|null, lines: list<array{sku: string, name: string, quantity: int}>}>  $orders
     * @param  array<string, string>  $decisions  manual decisions keyed by order number ('excluded' | 'reviewed')
     * @return array{groups: list<array<string, mixed>>, considered: int}
     */
    public function plan(array $orders, int $windowDays, array $decisions = []): array
    {
        $buckets = [];
        foreach ($orders as $order) {
            if ($order['email'] === '' || $order['destination'] === null) {
                continue;
            }
            $buckets[$order['email'].'|'.$this->key($order['recipient']).'|'.$order['destination']][] = $order;
        }
        $groups = [];
        foreach ($buckets as $key => $members) {
            if (count($members) < 2) {
                continue;
            }
            $groups[] = $this->group($key, $members, $windowDays, $decisions);
        }
        usort($groups, fn (array $left, array $right): int => [count($right['bundles']), count($right['members'])] <=> [count($left['bundles']), count($left['members'])] ?: strcmp($left['id'], $right['id']));

        return ['groups' => $groups, 'considered' => count($orders)];
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @param  array<string, string>  $decisions
     * @return array<string, mixed>
     */
    private function group(string $key, array $members, int $windowDays, array $decisions): array
    {
        $rows = [];
        $eligible = [];
        foreach ($members as $member) {
            $decision = $decisions[$member['number']] ?? null;
            $reasons = [];
            $review = [];
            if ($member['status'] !== 'awaiting_shipment') {
                $reasons[] = 'not_awaiting_shipment';
            }
            if ($decision === 'excluded') {
                $reasons[] = 'manually_excluded';
            }
            foreach (['paid_shipping' => 'paid_shipping', 'international' => 'international_or_customs', 'insured' => 'insured', 'gift' => 'gift', 'special' => 'special_handling'] as $flag => $reason) {
                if ($member[$flag] === true && $decision !== 'reviewed') {
                    $review[] = $reason;
                }
            }
            $state = $reasons !== [] ? 'excluded' : ($review !== [] ? 'review' : 'eligible');
            $row = ['number' => $member['number'], 'status' => $member['status'], 'created_at' => $member['created_at'], 'state' => $state, 'reasons' => [...$reasons, ...$review], 'decision' => $decision, 'warehouse' => $member['warehouse'], 'service' => $member['service'], 'confirmation' => $member['confirmation'], 'weight' => $member['weight'], 'lines' => $member['lines']];
            $rows[] = $row;
            if ($state === 'eligible') {
                $eligible[] = $row;
            }
        }
        $partitions = [];
        foreach ($eligible as $row) {
            $partitions[implode('|', [$row['warehouse'], $row['service'], $row['confirmation']])][] = $row;
        }
        $bundles = [];
        $bundled = [];
        foreach ($partitions as $partition) {
            usort($partition, fn (array $left, array $right): int => [$left['created_at']?->getTimestamp() ?? PHP_INT_MAX, $left['number']] <=> [$right['created_at']?->getTimestamp() ?? PHP_INT_MAX, $right['number']]);
            $anchor = $partition[0]['created_at'];
            $inside = array_values(array_filter($partition, fn (array $row): bool => $anchor !== null && $row['created_at'] !== null && $anchor->startOfDay()->diffInDays($row['created_at']->startOfDay(), true) <= $windowDays));
            if (count($inside) >= 2) {
                $bundles[] = $this->bundle($inside);
                foreach ($inside as $row) {
                    $bundled[$row['number']] = true;
                }
            }
        }
        foreach ($rows as $index => $row) {
            if ($row['state'] === 'eligible' && ! isset($bundled[$row['number']])) {
                $sameProfile = count(array_filter($eligible, fn (array $other): bool => [$other['warehouse'], $other['service'], $other['confirmation']] === [$row['warehouse'], $row['service'], $row['confirmation']])) > 1;
                $rows[$index]['state'] = 'alone';
                $rows[$index]['reasons'] = [$sameProfile ? 'outside_window' : 'no_compatible_partner'];
            }
        }
        $first = $members[0];

        return ['id' => substr(hash('sha256', $key), 0, 12), 'email' => $first['email'], 'recipient' => $first['recipient'], 'destination' => (string) $first['destination'], 'members' => $rows, 'bundles' => $bundles];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{orders: list<string>, lines: list<array{sku: string, name: string, quantity: int, allocations: array<string, int>}>, unidentified_lines: bool, weight: array{value: float, units: string}|null, notes: list<string>, packages_avoided: int}
     */
    private function bundle(array $rows): array
    {
        $lines = [];
        $unidentified = false;
        foreach ($rows as $row) {
            foreach ($row['lines'] as $line) {
                $sku = mb_strtolower(trim($line['sku']));
                if ($sku === '') {
                    $unidentified = true;
                    $key = 'name:'.mb_strtolower(trim($line['name']));
                } else {
                    $key = 'sku:'.$sku;
                }
                $lines[$key] ??= ['sku' => $line['sku'], 'name' => $line['name'], 'quantity' => 0, 'allocations' => []];
                $lines[$key]['quantity'] += $line['quantity'];
                $lines[$key]['allocations'][$row['number']] = ($lines[$key]['allocations'][$row['number']] ?? 0) + $line['quantity'];
            }
        }
        ksort($lines);
        $weights = array_column($rows, 'weight');
        $known = ! in_array(null, $weights, true) && count(array_unique(array_column(array_filter($weights), 'units'))) === 1;
        $notes = [];
        if ($rows[0]['service'] === '') {
            $notes[] = 'service_unspecified';
        }
        if ($rows[0]['warehouse'] === '') {
            $notes[] = 'ship_from_unspecified';
        }

        return [
            'orders' => array_column($rows, 'number'),
            'lines' => array_values($lines),
            'unidentified_lines' => $unidentified,
            'weight' => $known ? ['value' => round(array_sum(array_column($weights, 'value')), 3), 'units' => $weights[0]['units']] : null,
            'notes' => $notes,
            'packages_avoided' => count($rows) - 1,
        ];
    }

    /**
     * Operator-assumption scenario: what shipping the bundle's orders separately would cost against one combined package. Without both assumptions the saving is unknown; it is never derived from guessed package dimensions or carrier prices.
     */
    public function savings(int $orders, ?string $separateCost, ?string $combinedCost): ?string
    {
        if ($separateCost === null || $combinedCost === null || ! is_numeric($separateCost) || ! is_numeric($combinedCost)) {
            return null;
        }

        return (string) BigDecimal::of($separateCost)->multipliedBy($orders)->minus($combinedCost)->toScale(2, RoundingMode::HalfUp);
    }

    private function key(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value)) ?? '';
    }
}
