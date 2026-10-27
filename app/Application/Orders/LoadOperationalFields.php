<?php

namespace App\Application\Orders;

use App\Domain\Orders\OperationalFieldResolver;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyOrders;
use App\Models\OperationalField;
use App\Models\Store;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

class LoadOperationalFields
{
    public function __construct(private readonly ShipStationClientFactory $clients, private readonly ShopifyOrders $shopify, private readonly OperationalFieldResolver $resolver, private readonly FindStoreShipStationOrders $findOrders) {}

    /**
     * Read-only. Required fields that are missing or unreadable become findings; nothing is written to Shopify or ShipStation.
     *
     * @param  list<string>  $orderNumbers
     * @return array{rows: list<array{order: string, items: list<array{sku: string, name: string, quantity: int}>, fields: list<array{alias: string, state: string, value: string}>}>, findings: list<array{order: string, alias: string, state: string}>, missing: list<string>, ambiguous: list<string>, fields: list<array{alias: string, required: bool}>}
     */
    public function handle(Store $store, array $orderNumbers): array
    {
        $client = $this->clients->forStore($store);
        if ($client === null) {
            throw new LogicException('ShipStation is not configured for this store.');
        }
        $definitions = $store->operationalFields()->orderBy('alias')->get();
        $missing = $ambiguous = $orders = [];
        foreach ($orderNumbers as $number) {
            $matches = $this->findOrders->handle($store, $client, $number);
            match (count($matches)) {
                0 => $missing[] = $number,
                1 => $orders[$number] = $matches[0],
                default => $ambiguous[] = $number,
            };
        }
        $metafields = $definitions->contains('source_type', OperationalField::SOURCE_METAFIELD) ? $this->metafields($store, array_keys($orders)) : [];

        $rows = $findings = [];
        foreach ($orders as $number => $order) {
            $fields = [];
            foreach ($definitions as $definition) {
                $resolved = $this->resolver->resolve($definition->source_type, $definition->source, $metafields[(string) $number] ?? null, $order);
                $fields[] = ['alias' => $definition->alias] + $resolved;
                if ($definition->required && $resolved['state'] !== 'present') {
                    $findings[] = ['order' => (string) $number, 'alias' => $definition->alias, 'state' => $resolved['state']];
                }
            }
            $items = [];
            foreach (is_array($order['items'] ?? null) ? $order['items'] : [] as $item) {
                if (is_array($item)) {
                    $items[] = ['sku' => is_string($item['sku'] ?? null) ? $item['sku'] : '', 'name' => is_string($item['name'] ?? null) ? $item['name'] : '', 'quantity' => is_numeric($item['quantity'] ?? null) ? (int) $item['quantity'] : 0];
                }
            }
            $rows[] = ['order' => (string) $number, 'items' => $items, 'fields' => $fields];
        }

        return ['rows' => $rows, 'findings' => $findings, 'missing' => $missing, 'ambiguous' => $ambiguous, 'fields' => array_values($definitions->map(fn (OperationalField $definition): array => ['alias' => $definition->alias, 'required' => $definition->required])->all())];
    }

    /**
     * Orders whose Shopify record or metafields cannot be read are left out, so their values resolve as unreadable instead of absent.
     *
     * @param  list<int|string>  $numbers
     * @return array<string, list<array<string, mixed>>>
     */
    private function metafields(Store $store, array $numbers): array
    {
        $result = [];
        foreach (array_chunk(array_map('strval', $numbers), 50) as $chunk) {
            try {
                $found = $this->shopify->findByOrderNumbers($store, $chunk);
                $ids = [];
                foreach ($chunk as $number) {
                    $matches = $found[ltrim($number, '#')] ?? [];
                    if (count($matches) === 1 && isset($matches[0]['id'])) {
                        $ids[$number] = (string) $matches[0]['id'];
                    }
                }
                $byId = $this->shopify->orderMetafields($store, array_values($ids));
                foreach ($ids as $number => $id) {
                    if (array_key_exists($id, $byId)) {
                        $result[(string) $number] = $byId[$id];
                    }
                }
            } catch (Throwable $exception) {
                Log::warning('Operational field metafield lookup failed.', ['exception_type' => $exception::class]);
            }
        }

        return $result;
    }
}
