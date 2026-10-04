<?php

namespace App\Integrations\Shopify;

/**
 * Normalizes Shopify refund, dispute and gift card nodes into the arrays the payment reports read.
 */
class ShopifyPaymentNormalizer
{
    /**
     * @return list<array{created_at: string, note: string, total_refunded: float, transactions: list<array{kind: string, status: string, amount: float}>, refund_line_items: list<array{quantity: int, subtotal: float, line_item: array{name: string, sku: string}}>}>
     */
    public function refunds(mixed $refunds): array
    {
        $normalized = [];

        foreach (is_array($refunds) ? $refunds : [] as $refund) {
            if (! is_array($refund)) {
                continue;
            }

            $transactions = [];
            foreach (is_array($refund['transactions']['nodes'] ?? null) ? $refund['transactions']['nodes'] : [] as $transaction) {
                if (! is_array($transaction)) {
                    continue;
                }
                $money = $transaction['amountSet']['shopMoney'] ?? null;
                $transactions[] = ['kind' => strtolower(is_scalar($transaction['kind'] ?? null) ? (string) $transaction['kind'] : ''), 'status' => strtolower(is_scalar($transaction['status'] ?? null) ? (string) $transaction['status'] : ''), 'amount' => is_array($money) && is_numeric($money['amount'] ?? null) ? (float) $money['amount'] : 0.0];
            }

            $refundLineItems = [];
            foreach (is_array($refund['refundLineItems']['nodes'] ?? null) ? $refund['refundLineItems']['nodes'] : [] as $refundLineItem) {
                if (! is_array($refundLineItem)) {
                    continue;
                }
                $subtotal = $refundLineItem['subtotalSet']['shopMoney']['amount'] ?? null;
                $lineItem = is_array($refundLineItem['lineItem'] ?? null) ? $refundLineItem['lineItem'] : [];
                $refundLineItems[] = [
                    'quantity' => is_numeric($refundLineItem['quantity'] ?? null) ? (int) $refundLineItem['quantity'] : 0,
                    'subtotal' => is_numeric($subtotal) ? (float) $subtotal : 0.0,
                    'line_item' => [
                        'name' => is_scalar($lineItem['name'] ?? null) ? (string) $lineItem['name'] : '',
                        'sku' => is_scalar($lineItem['sku'] ?? null) ? (string) $lineItem['sku'] : '',
                    ],
                ];
            }

            $totalRefunded = $refund['totalRefundedSet']['shopMoney']['amount'] ?? null;
            $normalized[] = [
                'created_at' => is_scalar($refund['createdAt'] ?? null) ? (string) $refund['createdAt'] : '',
                'note' => is_scalar($refund['note'] ?? null) ? (string) $refund['note'] : '',
                'total_refunded' => is_numeric($totalRefunded) ? (float) $totalRefunded : 0.0,
                'transactions' => $transactions,
                'refund_line_items' => $refundLineItems,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{id: string, status: string, reason: string, network_reason_code: mixed, initiated_at: string, evidence_due_by: string|null, amount: float, currency: string, order_id: string, order_name: string}
     */
    public function dispute(array $node): array
    {
        $order = is_array($node['order'] ?? null) ? $node['order'] : [];
        $reason = is_array($node['reasonDetails'] ?? null) ? $node['reasonDetails'] : [];
        $amount = is_array($node['amount'] ?? null) ? $node['amount'] : [];
        $id = is_scalar($node['legacyResourceId'] ?? null) ? trim((string) $node['legacyResourceId']) : '';
        $orderId = is_scalar($order['legacyResourceId'] ?? null) ? trim((string) $order['legacyResourceId']) : '';

        return [
            'id' => ctype_digit($id) ? $id : '',
            'status' => strtolower(is_scalar($node['status'] ?? null) ? (string) $node['status'] : ''),
            'reason' => strtolower(is_scalar($reason['reason'] ?? null) ? (string) $reason['reason'] : ''),
            'network_reason_code' => $reason['networkReasonCode'] ?? null,
            'initiated_at' => is_scalar($node['initiatedAt'] ?? null) ? (string) $node['initiatedAt'] : '',
            'evidence_due_by' => is_scalar($node['evidenceDueBy'] ?? null) ? (string) $node['evidenceDueBy'] : null,
            'amount' => is_numeric($amount['amount'] ?? null) ? (float) $amount['amount'] : 0.0,
            'currency' => is_scalar($amount['currencyCode'] ?? null) ? (string) $amount['currencyCode'] : '',
            'order_id' => ctype_digit($orderId) ? $orderId : '',
            'order_name' => is_scalar($order['name'] ?? null) ? (string) $order['name'] : '',
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{id: string, masked_code: string, balance: float, initial_value: float, currency: string, expires_on: string|null, enabled: bool, created_at: string, customer_email: string}
     */
    public function giftCard(array $node): array
    {
        $balance = is_array($node['balance'] ?? null) ? $node['balance'] : [];
        $initialValue = is_array($node['initialValue'] ?? null) ? $node['initialValue'] : [];
        $customer = is_array($node['customer'] ?? null) ? $node['customer'] : [];

        return [
            'id' => is_scalar($node['id'] ?? null) ? (string) $node['id'] : '',
            'masked_code' => is_scalar($node['maskedCode'] ?? null) ? (string) $node['maskedCode'] : '',
            'balance' => (float) ($balance['amount'] ?? 0),
            'initial_value' => (float) ($initialValue['amount'] ?? 0),
            'currency' => is_scalar($balance['currencyCode'] ?? $initialValue['currencyCode'] ?? null) ? (string) ($balance['currencyCode'] ?? $initialValue['currencyCode']) : '',
            'expires_on' => is_scalar($node['expiresOn'] ?? null) ? (string) $node['expiresOn'] : null,
            'enabled' => ($node['enabled'] ?? false) === true,
            'created_at' => is_scalar($node['createdAt'] ?? null) ? (string) $node['createdAt'] : '',
            'customer_email' => is_scalar($customer['email'] ?? null) ? (string) $customer['email'] : '',
        ];
    }
}
