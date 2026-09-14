<?php

namespace App\Application\Orders;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Models\Store;

class UseMetafields
{
    public function __construct(private readonly ShopifyAdminGateway $shopify) {}

    /** @return array<string, mixed> */
    public function search(Store $store, string $namespace, string $key, string $value, ?string $start, ?string $end): array
    {
        return $this->shopify->searchOrdersByMetafield($store, $namespace, $key, $value, $start, $end);
    }

    /** @param list<string> $numbers @return list<array<string, mixed>> */
    public function lookup(Store $store, array $numbers, string $filter): array
    {
        $ordersByNumber = $this->shopify->findByOrderNumbers($store, $numbers);
        $orders = array_merge(...array_values($ordersByNumber ?: [[]]));
        $metafields = $this->shopify->orderMetafields($store, array_column($orders, 'id'));
        $rows = [];
        foreach ($numbers as $number) {
            $clean = ltrim($number, '#');
            $matches = $ordersByNumber[$clean] ?? [];
            if ($matches === []) {
                $rows[] = ['number' => $clean, 'found' => false, 'metafields' => []];

                continue;
            }
            foreach ($matches as $order) {
                $id = (string) ($order['id'] ?? '');
                $values = $metafields[$id] ?? [];
                if ($filter !== '') {
                    $values = array_values(array_filter($values, fn (array $metafield): bool => mb_stripos(($metafield['namespace'] ?? '').'.'.($metafield['key'] ?? ''), $filter) !== false || mb_stripos((string) ($metafield['value'] ?? ''), $filter) !== false));
                }
                $rows[] = ['number' => $clean, 'found' => true, 'id' => $id, 'name' => $order['name'] ?? '#'.$clean, 'metafields' => $values];
            }
        }

        return $rows;
    }
}
