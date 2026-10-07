<?php

namespace App\Application\Reports;

use App\Domain\Reports\OrderContributionAnalyzer;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\ShopifyOrderContribution;
use App\Models\Store;
use Throwable;

class RunOrderContributionReport
{
    public function __construct(private readonly ShopifyOrderContribution $shopify, private readonly ShipStationClientFactory $shipStation, private readonly OrderContributionAnalyzer $analyzer) {}

    /** @return ReportResult<array<string, mixed>> */
    public function handle(Store $store, string $startDate, string $endDate, ?string $after = null): ReportResult
    {
        $data = $this->shopify->collect($store, $startDate, $endDate, $after);
        $client = null;
        try {
            $client = $this->shipStation->forStore($store);
        } catch (Throwable) {
            $data['coverage'][] = 'ShipStation credentials are incomplete; Shopify data remains available.';
        }
        $rows = [];
        $coverage = $data['coverage'];
        $storeId = ctype_digit((string) $store->store_number) && (int) $store->store_number > 0 ? (int) $store->store_number : null;
        $names = array_count_values(array_map(fn (array $order): string => $this->number($order['name']), $data['orders']));
        foreach ($data['orders'] as $order) {
            $shipments = null;
            $problem = null;
            if ($client === null || $storeId === null) {
                $problem = 'ShipStation credentials and a store number are required to link labels safely.';
            } elseif ($names[$this->number($order['name'])] > 1) {
                $problem = 'Duplicate Shopify order names prevent safe label matching.';
            } else {
                try {
                    $matches = $client->findByOrderNumber($this->number($order['name']), $storeId);
                    $matchingIds = [];
                    foreach ($matches as $match) {
                        if ($this->number($match['orderNumber'] ?? '') !== $this->number($order['name']) || (string) ($match['advancedOptions']['storeId'] ?? '') !== (string) $storeId) {
                            continue;
                        }
                        $id = $match['orderId'] ?? null;
                        if (! is_scalar($id) || ! ctype_digit((string) $id) || (int) $id <= 0) {
                            throw new \UnexpectedValueException('Missing ShipStation order identity.');
                        }
                        $matchingIds[] = (int) $id;
                    }
                    if ($matchingIds === []) {
                        $problem = 'No exact ShipStation order match in this store.';
                    } else {
                        $all = [];
                        foreach (array_unique($matchingIds) as $matchingId) {
                            array_push($all, ...$client->getOrderCostShipments($matchingId, $storeId, $startDate, substr($data['checked_at'], 0, 10)));
                        }
                        $shipments = [];
                        foreach ($all as $label) {
                            if (! isset($label['orderId'])) {
                                $problem = 'Some ShipStation labels have no order identity.';

                                continue;
                            }
                            if (! in_array((int) $label['orderId'], $matchingIds, true)) {
                                continue;
                            }
                            if ($this->number($label['orderNumber'] ?? '') !== $this->number($order['name'])) {
                                $problem = 'A ShipStation label has a conflicting order number.';

                                continue;
                            }
                            $shipments[] = $label;
                        }
                    }
                } catch (Throwable) {
                    $problem = 'ShipStation label lookup could not be completed.';
                    $shipments = null;
                }
            }
            $rows[] = $this->analyzer->analyze($order, $data['analytics'][$order['id']] ?? null, $shipments, $data['currency'], $problem);
        }

        return new ReportResult(
            rows: $rows, scanned: count($rows), pages: $data['pages'], truncated: $data['truncated'],
            params: compact('startDate', 'endDate', 'after'),
            meta: ['currency' => $data['currency'], 'timezone' => $data['timezone'], 'checkedAt' => $data['checked_at'], 'coverage' => $coverage, 'nextAfter' => $data['next_after'],
                'reported' => count(array_filter($rows, fn (array $row): bool => $row['contribution'] !== null)),
                'incomplete' => count(array_filter($rows, fn (array $row): bool => $row['contribution'] === null)),
            ],
        );
    }

    private function number(mixed $value): string
    {
        return is_scalar($value) ? ltrim(trim((string) $value), '#') : '';
    }
}
