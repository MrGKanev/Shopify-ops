<?php

namespace Tests\Unit\Integrations\Shopify;

use App\Integrations\Shopify\ShopifyPaymentNormalizer;
use PHPUnit\Framework\TestCase;

class ShopifyPaymentNormalizerTest extends TestCase
{
    public function test_refunds_are_normalized_with_lowercase_kinds_and_numeric_amounts(): void
    {
        $refunds = (new ShopifyPaymentNormalizer)->refunds([[
            'createdAt' => '2026-10-02T10:00:00Z', 'note' => 'Damaged', 'totalRefundedSet' => ['shopMoney' => ['amount' => '12.50']],
            'transactions' => ['nodes' => [['kind' => 'REFUND', 'status' => 'SUCCESS', 'amountSet' => ['shopMoney' => ['amount' => '12.50']]]]],
            'refundLineItems' => ['nodes' => [['quantity' => '2', 'subtotalSet' => ['shopMoney' => ['amount' => '10.00']], 'lineItem' => ['name' => 'Tee', 'sku' => 'TEE']]]],
        ]]);

        $this->assertSame([[
            'created_at' => '2026-10-02T10:00:00Z', 'note' => 'Damaged', 'total_refunded' => 12.5,
            'transactions' => [['kind' => 'refund', 'status' => 'success', 'amount' => 12.5]],
            'refund_line_items' => [['quantity' => 2, 'subtotal' => 10.0, 'line_item' => ['name' => 'Tee', 'sku' => 'TEE']]],
        ]], $refunds);
    }

    public function test_malformed_refund_data_degrades_to_safe_defaults_instead_of_failing(): void
    {
        $normalizer = new ShopifyPaymentNormalizer;

        $this->assertSame([], $normalizer->refunds(null));
        $this->assertSame([], $normalizer->refunds('refunds'));
        $this->assertSame([], $normalizer->refunds(['not an array', 5]));

        $refund = $normalizer->refunds([['createdAt' => ['x'], 'totalRefundedSet' => ['shopMoney' => ['amount' => 'abc']], 'transactions' => ['nodes' => ['bad', ['kind' => ['x'], 'amountSet' => ['shopMoney' => null]]]], 'refundLineItems' => ['nodes' => ['bad', ['quantity' => 'many', 'lineItem' => 'x']]]]])[0];

        $this->assertSame(['', '', 0.0], [$refund['created_at'], $refund['note'], $refund['total_refunded']]);
        $this->assertSame([['kind' => '', 'status' => '', 'amount' => 0.0]], $refund['transactions']);
        $this->assertSame([['quantity' => 0, 'subtotal' => 0.0, 'line_item' => ['name' => '', 'sku' => '']]], $refund['refund_line_items']);
    }

    public function test_disputes_keep_only_numeric_identities_and_lowercase_enums(): void
    {
        $dispute = (new ShopifyPaymentNormalizer)->dispute([
            'legacyResourceId' => ' 123 ', 'status' => 'NEEDS_RESPONSE', 'reasonDetails' => ['reason' => 'FRAUDULENT', 'networkReasonCode' => '4837'],
            'initiatedAt' => '2026-10-01T00:00:00Z', 'evidenceDueBy' => '2026-10-20T00:00:00Z', 'amount' => ['amount' => '99.90', 'currencyCode' => 'USD'],
            'order' => ['legacyResourceId' => '555', 'name' => '#1001'],
        ]);

        $this->assertSame(['123', 'needs_response', 'fraudulent', '4837', '2026-10-20T00:00:00Z', 99.9, 'USD', '555', '#1001'], [$dispute['id'], $dispute['status'], $dispute['reason'], $dispute['network_reason_code'], $dispute['evidence_due_by'], $dispute['amount'], $dispute['currency'], $dispute['order_id'], $dispute['order_name']]);
    }

    public function test_a_dispute_with_non_numeric_ids_or_missing_fields_is_normalized_without_trusting_them(): void
    {
        $dispute = (new ShopifyPaymentNormalizer)->dispute(['legacyResourceId' => '12abc', 'order' => ['legacyResourceId' => 'gid://shopify/Order/5'], 'amount' => 'oops']);

        $this->assertSame(['', '', 0.0, '', null, null], [$dispute['id'], $dispute['order_id'], $dispute['amount'], $dispute['currency'], $dispute['evidence_due_by'], $dispute['network_reason_code']]);
    }

    public function test_gift_cards_default_missing_balances_and_require_an_explicit_true_for_enabled(): void
    {
        $normalizer = new ShopifyPaymentNormalizer;

        $card = $normalizer->giftCard(['id' => 'gid://shopify/GiftCard/1', 'maskedCode' => '•••• 1234', 'balance' => ['amount' => '25.00', 'currencyCode' => 'EUR'], 'initialValue' => ['amount' => '50'], 'expiresOn' => '2027-01-01', 'enabled' => true, 'createdAt' => '2026-01-01T00:00:00Z', 'customer' => ['email' => 'a@example.com']]);
        $bare = $normalizer->giftCard(['enabled' => 'yes', 'initialValue' => ['currencyCode' => 'USD']]);

        $this->assertSame([25.0, 50.0, 'EUR', true, 'a@example.com', '2027-01-01'], [$card['balance'], $card['initial_value'], $card['currency'], $card['enabled'], $card['customer_email'], $card['expires_on']]);
        $this->assertSame([0.0, 0.0, 'USD', false, '', null], [$bare['balance'], $bare['initial_value'], $bare['currency'], $bare['enabled'], $bare['customer_email'], $bare['expires_on']]);
    }
}
