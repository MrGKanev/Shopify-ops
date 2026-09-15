<?php

namespace App\Domain\Reports;

class CustomerLtvAnalyzer
{
    /** @param list<array<string, mixed>> $orders @return array{top_customers: list<array<string, mixed>>, cohorts: list<array<string, mixed>>, total_customers: int, total_revenue: float} */
    public function analyze(array $orders): array
    {
        $customers = [];
        foreach ($orders as $order) {
            $email = mb_strtolower(trim(is_scalar($order['email'] ?? null) ? (string) $order['email'] : ''));
            if ($email === '' || ($order['cancelled_at'] ?? null)) {
                continue;
            }
            $createdAt = is_scalar($order['created_at'] ?? null) ? (string) $order['created_at'] : '';
            $customers[$email] ??= ['email' => $email, 'orders' => 0, 'total' => 0.0, 'first' => $createdAt, 'last' => $createdAt];
            $customers[$email]['orders']++;
            $customers[$email]['total'] += is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0;
            if ($createdAt !== '' && ($customers[$email]['first'] === '' || $createdAt < $customers[$email]['first'])) {
                $customers[$email]['first'] = $createdAt;
            }
            if ($createdAt > $customers[$email]['last']) {
                $customers[$email]['last'] = $createdAt;
            }
        }

        $top = array_values($customers);
        usort($top, fn (array $first, array $second): int => $second['total'] <=> $first['total']);
        $top = array_slice($top, 0, 100);
        foreach ($top as &$customer) {
            $customer['average'] = $customer['total'] / $customer['orders'];
            $customer['first_date'] = substr($customer['first'], 0, 10);
            $customer['last_date'] = substr($customer['last'], 0, 10);
        }
        unset($customer);

        $cohorts = [];
        foreach ($customers as $customer) {
            $month = substr($customer['first'], 0, 7);
            if ($month === '') {
                continue;
            }
            $cohorts[$month] ??= ['month' => $month, 'customers' => 0, 'repeat_buyers' => 0, 'orders' => 0, 'revenue' => 0.0];
            $cohorts[$month]['customers']++;
            $cohorts[$month]['repeat_buyers'] += $customer['orders'] > 1 ? 1 : 0;
            $cohorts[$month]['orders'] += $customer['orders'];
            $cohorts[$month]['revenue'] += $customer['total'];
        }
        ksort($cohorts);
        foreach ($cohorts as &$cohort) {
            $cohort['retention_rate'] = round($cohort['repeat_buyers'] / $cohort['customers'] * 100, 1);
            $cohort['average_orders'] = round($cohort['orders'] / $cohort['customers'], 2);
            $cohort['average_revenue'] = $cohort['revenue'] / $cohort['customers'];
        }

        return ['top_customers' => $top, 'cohorts' => array_values($cohorts), 'total_customers' => count($customers), 'total_revenue' => array_sum(array_column($customers, 'total'))];
    }
}
