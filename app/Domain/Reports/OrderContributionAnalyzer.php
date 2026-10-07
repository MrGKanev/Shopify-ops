<?php

namespace App\Domain\Reports;

class OrderContributionAnalyzer
{
    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>|null  $analytics
     * @param  list<array<string, mixed>>|null  $shipments
     * @return array<string, mixed>
     */
    public function analyze(array $order, ?array $analytics, ?array $shipments, string $currency, ?string $shippingProblem = null): array
    {
        $missing = [];
        $revenue = $analytics === null ? null : round((float) $analytics['net_sales'] + (float) $analytics['shipping_charges'], 2);
        $reportedCogs = $analytics === null ? null : round((float) $analytics['cost_of_goods_sold'], 2);
        $cogs = $reportedCogs;
        if ($analytics === null) {
            $missing[] = 'Historical cost and net revenue unavailable from Shopify analytics.';
        } elseif (abs((float) $analytics['net_sales_without_cost_recorded']) > 0.00001 || $reportedCogs === 0.0 || $reportedCogs < 0) {
            $cogs = null;
            $missing[] = 'Shopify cost coverage is incomplete or zero cost cannot be distinguished from missing historical cost.';
        }
        $fees = $this->fees($order, $currency);
        if ($fees['total'] === null) {
            $missing[] = 'Payment fees are incomplete, unavailable or in another currency.';
        }
        $labels = [];
        $seen = [];
        $shipping = 0.0;
        $shippingComplete = $shipments !== null && $shipments !== [];
        if ($shippingProblem !== null) {
            $missing[] = $shippingProblem;
            $shippingComplete = false;
        }
        foreach ($shipments ?? [] as $shipment) {
            $id = $this->text($shipment['shipmentId'] ?? null);
            if ($id === '') {
                $shippingComplete = false;

                continue;
            }
            if (isset($seen[$id])) {
                $fields = array_flip(['shipmentCost', 'insuranceCost', 'currencyCode', 'currency', 'voided', 'isReturnLabel']);
                if (array_intersect_key($seen[$id], $fields) !== array_intersect_key($shipment, $fields)) {
                    $shippingComplete = false;
                }

                continue;
            }
            $seen[$id] = $shipment;
            $cost = $this->amount($shipment['shipmentCost'] ?? null);
            $insurance = $this->amount($shipment['insuranceCost'] ?? null);
            $labelCurrency = strtoupper($this->text($shipment['currencyCode'] ?? $shipment['currency'] ?? null));
            $voided = $shipment['voided'] ?? null;
            $return = $shipment['isReturnLabel'] ?? null;
            $valid = $cost !== null && $insurance !== null && $cost >= 0 && $insurance >= 0;
            if (! $valid || $labelCurrency !== $currency || ! is_bool($voided) || ! is_bool($return)) {
                $shippingComplete = false;
            }
            if ($voided !== false) {
                $shippingComplete = false;
            }
            if ($valid && $voided === false && $labelCurrency === $currency) {
                $shipping += $cost + $insurance;
            }
            $labels[] = ['id' => $id, 'order_id' => $this->text($shipment['orderId'] ?? null), 'carrier' => $this->text($shipment['carrierCode'] ?? null), 'service' => $this->text($shipment['serviceCode'] ?? null), 'ship_date' => $this->text($shipment['shipDate'] ?? null), 'cost' => $cost, 'insurance' => $insurance, 'currency' => preg_match('/^[A-Z]{3}$/', $labelCurrency) === 1 ? $labelCurrency : null, 'voided' => $voided, 'return' => $return];
        }
        if (! $shippingComplete) {
            $missing[] = 'Shipping cost is incomplete: missing labels, costs, currency or unconfirmed void refunds.';
        }
        $shopifyContribution = $revenue !== null && $cogs !== null && $fees['total'] !== null ? round($revenue - $cogs - $fees['total'], 2) : null;
        $contribution = $shopifyContribution !== null && $shippingComplete ? round($shopifyContribution - $shipping, 2) : null;
        $orderTotal = $this->money(data_get($order, 'currentTotalPriceSet.shopMoney'), $currency);
        $orderTax = $this->money(data_get($order, 'currentTotalTaxSet.shopMoney'), $currency);

        return [
            'shopify_id' => $this->text($order['legacyResourceId'] ?? null), 'order_number' => $this->text($order['name'] ?? null),
            'created_at' => $this->text($order['createdAt'] ?? null), 'currency' => $currency,
            'revenue' => $revenue, 'reported_cogs' => $reportedCogs, 'cogs' => $cogs,
            'fees' => $fees['total'], 'observed_fees' => $fees['observed'], 'fee_details' => $fees['details'],
            'shipping' => $shippingComplete ? round($shipping, 2) : null, 'labels' => $labels,
            'shopify_contribution' => $shopifyContribution, 'contribution' => $contribution,
            'status' => $contribution === null ? 'incomplete' : 'reported_basis',
            'missing' => array_values(array_unique($missing)),
            'order_value_ex_tax' => $orderTotal !== null && $orderTax !== null ? round($orderTotal - $orderTax, 2) : null,
        ];
    }

    /** @param array<string, mixed> $order
     * @return array{total: float|null, observed: float|null, details: list<array<string, mixed>>} */
    private function fees(array $order, string $currency): array
    {
        $complete = ($order['fees_available'] ?? false) === true;
        $transactions = is_array($order['transactions'] ?? null) ? $order['transactions'] : [];
        $total = 0.0;
        $count = 0;
        $observed = 0;
        $seen = [];
        $details = [];
        foreach ($transactions as $transaction) {
            if (! is_array($transaction)) {
                $complete = false;

                continue;
            }
            if (($transaction['status'] ?? null) !== 'SUCCESS') {
                if (in_array($transaction['status'] ?? null, ['PENDING', 'AWAITING_RESPONSE'], true)) {
                    $complete = false;
                }

                continue;
            }
            if (! in_array($transaction['kind'] ?? null, ['SALE', 'CAPTURE', 'REFUND'], true)) {
                continue;
            }
            $count++;
            $entries = $transaction['fees'] ?? null;
            if (($transaction['gateway'] ?? null) !== 'shopify_payments' || ($transaction['test'] ?? null) !== false || ! is_array($entries) || $entries === []) {
                $complete = false;

                continue;
            }
            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    $complete = false;

                    continue;
                }
                $id = $this->text($entry['id'] ?? null);
                if ($id === '') {
                    $complete = false;

                    continue;
                }
                if (isset($seen[$id])) {
                    if ($seen[$id] !== $entry) {
                        $complete = false;
                    }

                    continue;
                }
                $seen[$id] = $entry;
                $amount = $this->money($entry['amount'] ?? null, $currency);
                $tax = $this->money($entry['taxAmount'] ?? null, $currency);
                $details[] = ['id' => $id, 'transaction_id' => $this->text($transaction['id'] ?? null), 'kind' => $transaction['kind'], 'amount' => is_array($entry['amount'] ?? null) ? $entry['amount'] : null, 'tax' => is_array($entry['taxAmount'] ?? null) ? $entry['taxAmount'] : null];
                if ($amount === null || $tax === null) {
                    $complete = false;

                    continue;
                }
                $observed++;
                $total += $amount + $tax;
            }
        }

        return ['total' => $complete && $count > 0 ? round($total, 2) : null, 'observed' => $observed > 0 ? round($total, 2) : null, 'details' => $details];
    }

    private function amount(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private function money(mixed $money, string $currency): ?float
    {
        return is_array($money) && ($money['currencyCode'] ?? null) === $currency ? $this->amount($money['amount'] ?? null) : null;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
