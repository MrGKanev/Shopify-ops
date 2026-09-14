<?php

namespace App\Domain\Reports;

class DuplicateOrderClusterer
{
    /**
     * Ports legacy `Comparator::findDuplicates()` for Run Audit's inline
     * "N potential duplicates detected" panel — a different tool from
     * `DuplicateOrderAnalyzer` (the `dupes` report, 10-minute pair window).
     *
     * @param  list<array<string, mixed>>  $shopifyOrders
     * @return list<array{email: string, amount: string, orders: list<array<string, mixed>>}>
     */
    public function cluster(array $shopifyOrders): array
    {
        $groups = [];
        foreach ($shopifyOrders as $order) {
            $email = strtolower(trim((string) ($order['email'] ?? '')));
            $amount = round((float) ($order['total_price'] ?? 0), 0);
            if ($email === '' || $amount <= 0) {
                continue;
            }
            $key = $email.'|'.$amount;
            $groups[$key][] = $order;
        }

        $duplicates = [];
        foreach ($groups as $key => $orders) {
            if (count($orders) < 2) {
                continue;
            }

            usort($orders, fn (array $a, array $b): int => strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')));

            $cluster = [$orders[0]];
            for ($i = 1; $i < count($orders); $i++) {
                $prev = strtotime((string) ($orders[$i - 1]['created_at'] ?? '0'));
                $curr = strtotime((string) ($orders[$i]['created_at'] ?? '0'));
                if ($curr - $prev <= 86400) {
                    $cluster[] = $orders[$i];
                } else {
                    if (count($cluster) >= 2) {
                        [$email, $amount] = explode('|', $key);
                        $duplicates[] = ['email' => $email, 'amount' => $amount, 'orders' => array_reverse($cluster)];
                    }
                    $cluster = [$orders[$i]];
                }
            }
            if (count($cluster) >= 2) {
                [$email, $amount] = explode('|', $key);
                $duplicates[] = ['email' => $email, 'amount' => $amount, 'orders' => array_reverse($cluster)];
            }
        }

        usort($duplicates, fn (array $a, array $b): int => strcmp((string) ($b['orders'][0]['created_at'] ?? ''), (string) ($a['orders'][0]['created_at'] ?? '')));

        return $duplicates;
    }
}
