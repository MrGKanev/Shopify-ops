<?php

namespace App\Integrations\Shopify;

use App\Integrations\Shopify\Contracts\ShopifyTransport;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Throwable;
use UnexpectedValueException;

class ShopifyOrderContribution
{
    public function __construct(private readonly ShopifyTransport $transport) {}

    /** @return array{orders: list<array<string, mixed>>, analytics: array<string, array<string, mixed>>, currency: string, timezone: string, coverage: list<string>, pages: int, truncated: bool, checked_at: string, next_after: string|null} */
    public function collect(Store $store, string $startDate, string $endDate, ?string $after = null): array
    {
        $shop = $this->transport->graphql($store, ShopifyQueries::get('OrderContributionShop'));
        $currency = data_get($shop, 'data.shop.currencyCode');
        $timezone = data_get($shop, 'data.shop.ianaTimezone');
        if (! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || ! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new UnexpectedValueException('Shopify did not confirm its reporting currency and timezone.');
        }
        $scopes = data_get($shop, 'data.currentAppInstallation.accessScopes', []);
        $granted = is_array($scopes) ? array_column($scopes, 'handle') : [];
        $start = CarbonImmutable::parse($startDate, $timezone)->startOfDay();
        $end = CarbonImmutable::parse($endDate, $timezone)->addDay()->startOfDay();
        $checked = CarbonImmutable::now($timezone);
        $coverage = [];
        $olderAccess = $start->gte($checked->subDays(60)) || in_array('read_all_orders', $granted, true);
        if (! $olderAccess) {
            $coverage[] = 'Access to orders older than 60 days was not confirmed; read_all_orders is required.';
        }
        $response = $this->transport->graphql($store, ShopifyQueries::get('OrderContributionOrders'), ['search' => 'status:any created_at:>='.$start->toIso8601String().' created_at:<'.$end->toIso8601String(), 'after' => $after]);
        $connection = data_get($response, 'data.orders');
        if (! is_array($connection) || ! is_array($connection['edges'] ?? null) || count($connection['edges']) > 25 || ! is_bool($connection['pageInfo']['hasNextPage'] ?? null)) {
            throw new UnexpectedValueException('Shopify returned invalid contribution pagination.');
        }
        $nextAfter = $connection['pageInfo']['hasNextPage'] ? ($connection['pageInfo']['endCursor'] ?? null) : null;
        if ($connection['pageInfo']['hasNextPage'] && (! is_string($nextAfter) || $nextAfter === '' || $nextAfter === $after)) {
            throw new UnexpectedValueException('Shopify returned invalid contribution pagination.');
        }
        $data = ['edges' => $connection['edges'], 'pages' => 1, 'truncated' => $connection['pageInfo']['hasNextPage']];
        $orders = [];
        foreach ($data['edges'] as $edge) {
            if (! is_array($edge)) {
                throw new UnexpectedValueException('Shopify returned an invalid contribution order.');
            }
            $node = $edge['node'] ?? null;
            if (! is_array($node) || ! is_string($node['id'] ?? null) || preg_match('~^gid://shopify/Order/[0-9]+$~', $node['id']) !== 1 || ! is_scalar($node['legacyResourceId'] ?? null) || ! ctype_digit((string) $node['legacyResourceId']) || ! is_string($node['name'] ?? null)) {
                throw new UnexpectedValueException('Shopify returned an invalid contribution order.');
            }
            $node['fees_available'] = false;
            try {
                $fees = $this->transport->graphql($store, ShopifyQueries::get('OrderContributionFees'), ['id' => $node['id']]);
                $feeOrder = data_get($fees, 'data.order');
                if (! is_array($feeOrder) || ($feeOrder['id'] ?? null) !== $node['id'] || ! is_array($feeOrder['transactions'] ?? null)) {
                    throw new UnexpectedValueException('Shopify returned incomplete fee data.');
                }
                $node['transactions'] = $feeOrder['transactions'];
                $node['fees_available'] = count($feeOrder['transactions']) < 100;
            } catch (Throwable) {
                $node['transactions'] = [];
            }
            $orders[] = $node;
        }
        $analytics = [];
        if ($orders !== []) {
            if (! in_array('read_reports', $granted, true)) {
                $coverage[] = 'Shopify analytics unavailable: read_reports and the required customer-data access are needed.';
            } else {
                try {
                    $ids = implode(', ', array_map(fn (array $order): string => (string) $order['legacyResourceId'], $orders));
                    $query = "FROM sales SHOW net_sales, shipping_charges, cost_of_goods_sold, net_sales_with_cost_recorded, net_sales_without_cost_recorded WHERE order_id IN ({$ids}) GROUP BY order_id SINCE {$startDate} UNTIL ".$checked->toDateString().' LIMIT 26';
                    $result = $this->transport->graphql($store, ShopifyQueries::get('OrderContributionAnalytics'), ['query' => $query]);
                    $table = data_get($result, 'data.shopifyqlQuery');
                    if (! is_array($table) || ($table['parseErrors'] ?? null) !== [] || ! is_array($table['tableData']['rows'] ?? null) || ! is_array($table['tableData']['columns'] ?? null)) {
                        throw new UnexpectedValueException('Shopify analytics could not confirm the requested metrics.');
                    }
                    $required = ['order_id', 'net_sales', 'shipping_charges', 'cost_of_goods_sold', 'net_sales_with_cost_recorded', 'net_sales_without_cost_recorded'];
                    if (array_diff($required, array_column($table['tableData']['columns'], 'name')) !== [] || count($table['tableData']['rows']) > count($orders)) {
                        throw new UnexpectedValueException('Shopify analytics returned an unexpected schema.');
                    }
                    foreach ($table['tableData']['columns'] as $column) {
                        if (is_array($column) && in_array($column['name'] ?? null, array_slice($required, 1), true) && ($column['dataType'] ?? null) !== 'MONEY') {
                            throw new UnexpectedValueException('Shopify analytics returned an unexpected monetary type.');
                        }
                    }
                    foreach ($table['tableData']['rows'] as $row) {
                        if (! is_array($row) || ! is_scalar($row['order_id'] ?? null)) {
                            throw new UnexpectedValueException('Shopify analytics returned an invalid order.');
                        }
                        $id = preg_replace('~^gid://shopify/Order/~', '', (string) $row['order_id']);
                        if (! in_array($id, array_map(fn (array $order): string => (string) $order['legacyResourceId'], $orders), true) || isset($analytics['gid://shopify/Order/'.$id])) {
                            throw new UnexpectedValueException('Shopify analytics returned ambiguous orders.');
                        }
                        foreach (array_slice($required, 1) as $metric) {
                            if (! is_numeric($row[$metric] ?? null) || ! is_finite((float) $row[$metric])) {
                                throw new UnexpectedValueException('Shopify analytics returned missing monetary data.');
                            }
                        }
                        $analytics['gid://shopify/Order/'.$id] = [
                            'net_sales' => $row['net_sales'], 'shipping_charges' => $row['shipping_charges'],
                            'cost_of_goods_sold' => $row['cost_of_goods_sold'],
                            'net_sales_with_cost_recorded' => $row['net_sales_with_cost_recorded'],
                            'net_sales_without_cost_recorded' => $row['net_sales_without_cost_recorded'],
                        ];
                    }
                } catch (Throwable) {
                    $analytics = [];
                    $coverage[] = 'Shopify analytics unavailable or its schema was not confirmed. Historical costs and net revenue remain unknown.';
                }
            }
        }

        return ['orders' => $orders, 'analytics' => $analytics, 'currency' => $currency, 'timezone' => $timezone, 'coverage' => $coverage, 'pages' => $data['pages'], 'truncated' => $data['truncated'] || ! $olderAccess, 'checked_at' => $checked->toIso8601String(), 'next_after' => $nextAfter];
    }
}
