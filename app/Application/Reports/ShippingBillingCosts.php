<?php

namespace App\Application\Reports;

use App\Domain\Reports\ShippingBillingAnalyzer;
use App\Models\ShippingBillingEntry;
use App\Models\Store;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

class ShippingBillingCosts
{
    public function __construct(private readonly ShippingBillingAnalyzer $analyzer) {}

    /** @param list<string> $shipmentIds
     * @return array{groups: list<array<string, mixed>>, truncated: bool} */
    public function forShipments(Store $store, array $shipmentIds, ?string $asOf = null): array
    {
        if ($shipmentIds === []) {
            return ['groups' => [], 'truncated' => false];
        }
        $entries = $store->shippingBillingEntries()->select(['id', 'source_account', 'shipment_id', 'order_id', 'order_number', 'type', 'amount', 'currency', 'expected_refund', 'refund_due_at', 'excluded', 'carrier', 'service', 'package', 'skus', 'tracking_number'])->where('account_fingerprint', ShippingBillingEntry::accountFingerprint($store))->whereIn('shipment_id', array_unique($shipmentIds))->whereDate('posted_at', '<=', $asOf ?? CarbonImmutable::now($store->shopTimezone())->toDateString())->orderBy('id')->limit(10001)->get();

        return ['groups' => $this->analyzer->analyze(array_values($entries->take(10000)->map(fn (ShippingBillingEntry $entry): array => $entry->toArray())->all()), CarbonImmutable::now($store->shopTimezone())), 'truncated' => $entries->count() > 10000];
    }

    /** @param list<array<string, mixed>> $groups
     * @return array<string, mixed>|null */
    public function match(array $groups, string $shipmentId, string $orderId, string $orderNumber, ?string $currency = null): ?array
    {
        $matches = array_values(array_filter($groups, fn (array $group): bool => $group['shipment_id'] === $shipmentId));
        if (count($matches) !== 1) {
            return null;
        }
        $group = $matches[0];

        return ! $group['identity_conflict'] && $group['order_id'] === $orderId && $group['order_number'] === ltrim(trim($orderNumber), '#') && ($currency === null || $group['currency'] === $currency) ? $group : null;
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>> */
    public function contribution(Store $store, array $rows, string $currency): array
    {
        $ids = [];
        foreach ($rows as $row) {
            foreach ($row['labels'] as $label) {
                $ids[] = $label['id'];
            }
        }
        $data = $this->forShipments($store, $ids);
        foreach ($rows as &$row) {
            $total = BigDecimal::zero();
            $complete = ! $data['truncated'] && $row['labels'] !== [];
            foreach ($row['labels'] as &$label) {
                $group = $data['truncated'] || (isset($label['currency']) && $label['currency'] !== $currency) ? null : $this->match($data['groups'], $label['id'], $label['order_id'], $row['order_number'], $currency);
                $label['imported_billing'] = $group;
                if ($group === null || $group['billed_cost'] === null) {
                    $complete = false;
                } else {
                    $total = $total->plus($group['billed_cost']);
                }
            }
            unset($label);
            $row['imported_shipping'] = $complete ? (string) $total : null;
            $row['contribution_on_imported_billing'] = $complete && $row['shopify_contribution'] !== null ? (string) BigDecimal::of(number_format($row['shopify_contribution'], 2, '.', ''))->minus($total) : null;
        }
        unset($row);

        return $rows;
    }
}
