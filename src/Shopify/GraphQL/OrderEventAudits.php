<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

/**
 * Event-backed order audit workflows.
 */
class OrderEventAudits
{
    public function __construct(private readonly OrderFetcher $orders)
    {
    }

    /**
     * Fetches orders whose shipping address was changed after the order was placed.
     *
     * @return array<int, array<string, mixed>> each entry has 'order' + 'changed_at'
     */
    public function fetchOrdersWithAddressChanges(string $startDate, string $endDate): array
    {
        $changed = $this->changedAddressOrderIds($startDate, $endDate);
        if (empty($changed)) return [];

        $ordersById = $this->orders->fetchOrdersByIds(
            array_keys($changed),
            Queries::orderCoreFields()
                . Queries::shippingAddressFields()
        );

        $orders = [];
        foreach ($ordersById as $id => $order) {
            if (!isset($changed[$id])) {
                continue;
            }

            $orders[] = [
                'order'      => $order,
                'changed_at' => $changed[$id],
            ];
        }

        usort($orders, fn($a, $b) => strcmp($b['changed_at'], $a['changed_at']));

        return $orders;
    }

    /**
     * Finds orders that had content edits after placement.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchEditedOrders(string $startDate, string $endDate): array
    {
        $byOrder = [];
        foreach ($this->orders->fetchEventsByQuery(Queries::orderEventDateRangeQuery($startDate, $endDate)) as $ev) {
            if (!Normalizer::isOrderEditEvent($ev)) {
                continue;
            }

            $id = (string)($ev['subject_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $ts = $ev['created_at'] ?? '';
            if (!isset($byOrder[$id])) {
                $byOrder[$id] = ['latest_at' => $ts, 'summary' => []];
            } elseif ($ts > $byOrder[$id]['latest_at']) {
                $byOrder[$id]['latest_at'] = $ts;
            }

            $short = ucfirst((string)($ev['message'] ?? ''));
            if ($short !== '' && count($byOrder[$id]['summary']) < 4 && !in_array($short, $byOrder[$id]['summary'], true)) {
                $byOrder[$id]['summary'][] = $short;
            }
        }

        if (empty($byOrder)) return [];

        $rows = [];
        $ordersById = $this->orders->fetchOrdersByIds(
            array_keys($byOrder),
            Queries::orderCoreFields()
        );

        foreach ($ordersById as $oid => $o) {
            $ev        = $byOrder[$oid] ?? [];
            $createdTs = strtotime($o['created_at'] ?? '');
            $editedTs  = strtotime($ev['latest_at']  ?? '');
            $diffMins  = ($createdTs && $editedTs) ? max(0, (int)(($editedTs - $createdTs) / 60)) : 0;
            $rows[] = [
                'shopify_id'   => (string)$oid,
                'order_number' => $o['name']                ?? '',
                'created_at'   => substr($o['created_at']   ?? '', 0, 10),
                'edited_at'    => substr($ev['latest_at']   ?? '', 0, 16),
                'diff_mins'    => $diffMins,
                'email'        => $o['email']               ?? '',
                'total'        => $o['total_price']         ?? '',
                'financial'    => $o['financial_status']    ?? '',
                'fulfillment'  => $o['fulfillment_status']  ?? '',
                'edit_summary' => $ev['summary']            ?? [],
            ];
        }

        usort($rows, fn($a, $b) => strcmp($b['edited_at'], $a['edited_at']));
        return $rows;
    }

    /**
     * @return array<int, array{order: array, changed_at: string, fulfillment_at: string}>
     */
    public function fetchPostShipAddressChanges(string $startDate, string $endDate): array
    {
        $changed = $this->changedAddressOrderIds($startDate, $endDate);
        if (empty($changed)) return [];

        $orders = [];
        $ordersById = $this->orders->fetchOrdersByIds(
            array_keys($changed),
            Queries::orderCoreFields()
                . Queries::shippingAddressFields()
                . Queries::fulfillmentFields()
        );

        foreach ($ordersById as $oid => $o) {
            $changedAt = $changed[$oid] ?? '';

            $firstFulfillAt = '';
            foreach ($o['fulfillments'] ?? [] as $f) {
                $fa = $f['created_at'] ?? '';
                if ($fa && (!$firstFulfillAt || $fa < $firstFulfillAt)) {
                    $firstFulfillAt = $fa;
                }
            }

            if (!$firstFulfillAt || $changedAt <= $firstFulfillAt) continue;

            $orders[] = [
                'order'          => $o,
                'changed_at'     => $changedAt,
                'fulfillment_at' => $firstFulfillAt,
            ];
        }

        usort($orders, fn($a, $b) => strcmp($b['changed_at'], $a['changed_at']));
        return $orders;
    }

    /**
     * @return array<string, string>
     */
    private function changedAddressOrderIds(string $startDate, string $endDate): array
    {
        $changed = [];
        foreach ($this->orders->fetchEventsByQuery(Queries::orderEventDateRangeQuery($startDate, $endDate)) as $ev) {
            if (!Normalizer::isAddressChangeEvent($ev)) {
                continue;
            }

            $id = (string)($ev['subject_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $ts = $ev['created_at'] ?? '';
            if (!isset($changed[$id]) || $ts > $changed[$id]) {
                $changed[$id] = $ts;
            }
        }

        return $changed;
    }
}
