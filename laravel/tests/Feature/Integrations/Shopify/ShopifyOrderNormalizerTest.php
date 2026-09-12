<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\ShopifyOrderNormalizer;
use Tests\TestCase;

class ShopifyOrderNormalizerTest extends TestCase
{
    public function test_returns_legacy_compatible_addresses_items_and_fulfillments(): void
    {
        $order = (new ShopifyOrderNormalizer)->normalize([
            'id' => 'gid://shopify/Order/123456789',
            'legacyResourceId' => '123456789',
            'name' => '#65075',
            'createdAt' => '2026-08-30T10:15:00Z',
            'processedAt' => '2026-08-30T10:16:00Z',
            'closedAt' => '2026-09-03T09:00:00Z',
            'cancelledAt' => null,
            'cancelReason' => 'CUSTOMER',
            'email' => 'buyer@example.com',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'FULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '129.90']],
            'note' => 'Handle carefully',
            'tags' => ['vip', 'priority'],
            'shippingAddress' => [
                'firstName' => 'Ada',
                'lastName' => 'Lovelace',
                'name' => 'Ada Lovelace',
                'company' => 'Analytical Engines',
                'address1' => '1 Computing Lane',
                'address2' => 'Suite 2',
                'city' => 'London',
                'province' => 'England',
                'provinceCode' => 'ENG',
                'country' => 'United Kingdom',
                'countryCodeV2' => 'GB',
                'zip' => 'SW1A 1AA',
                'phone' => '+44123456789',
            ],
            'billingAddress' => null,
            'lineItems' => ['nodes' => [[
                'id' => 'gid://shopify/LineItem/987',
                'title' => 'Blue Widget',
                'name' => 'Blue Widget - Large',
                'sku' => 'WIDGET-BLUE-L',
                'quantity' => 3,
                'variantTitle' => 'Large',
                'originalUnitPriceSet' => ['shopMoney' => ['amount' => '39.95']],
            ]]],
            'fulfillments' => [[
                'id' => 'gid://shopify/Fulfillment/456',
                'legacyResourceId' => '456',
                'createdAt' => '2026-08-31T08:00:00Z',
                'status' => 'SUCCESS',
                'displayStatus' => 'DELIVERED',
                'trackingInfo' => [[
                    'company' => 'DHL',
                    'number' => 'TRACK-1',
                    'url' => 'https://tracking.example/TRACK-1',
                ]],
                'fulfillmentLineItems' => ['edges' => [[
                    'node' => [
                        'quantity' => 2,
                        'lineItem' => [
                            'id' => 'gid://shopify/LineItem/987',
                            'title' => 'Blue Widget',
                            'name' => 'Blue Widget - Large',
                            'sku' => 'WIDGET-BLUE-L',
                            'quantity' => 3,
                            'variantTitle' => 'Large',
                            'originalUnitPriceSet' => ['shopMoney' => ['amount' => '39.95']],
                        ],
                    ],
                ]]],
            ]],
            'refunds' => [[
                'id' => 'gid://shopify/Refund/654',
                'legacyResourceId' => '654',
                'createdAt' => '2026-09-02T11:30:00Z',
                'note' => 'Customer return',
                'totalRefundedSet' => ['shopMoney' => ['amount' => '39.95']],
                'transactions' => ['nodes' => [[
                    'id' => 'gid://shopify/OrderTransaction/321',
                    'kind' => 'REFUND',
                    'status' => 'SUCCESS',
                    'amountSet' => ['shopMoney' => ['amount' => '39.95']],
                ]]],
            ]],
        ]);

        $this->assertSame([
            'id' => 123456789,
            'order_number' => 65075,
            'name' => '#65075',
            'created_at' => '2026-08-30T10:15:00Z',
            'cancelled_at' => null,
            'email' => 'buyer@example.com',
            'financial_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'total_price' => '129.90',
            'admin_graphql_api_id' => 'gid://shopify/Order/123456789',
            'processed_at' => '2026-08-30T10:16:00Z',
            'closed_at' => '2026-09-03T09:00:00Z',
            'cancel_reason' => 'customer',
            'shipping_address' => [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'name' => 'Ada Lovelace',
                'company' => 'Analytical Engines',
                'address1' => '1 Computing Lane',
                'address2' => 'Suite 2',
                'city' => 'London',
                'province' => 'England',
                'province_code' => 'ENG',
                'country' => 'United Kingdom',
                'country_code' => 'GB',
                'zip' => 'SW1A 1AA',
                'phone' => '+44123456789',
            ],
            'billing_address' => null,
            'note' => 'Handle carefully',
            'tags' => ['vip', 'priority'],
            'line_items' => [[
                'id' => 987,
                'title' => 'Blue Widget',
                'name' => 'Blue Widget - Large',
                'sku' => 'WIDGET-BLUE-L',
                'quantity' => 3,
                'variant_title' => 'Large',
                'price' => '39.95',
                'admin_graphql_api_id' => 'gid://shopify/LineItem/987',
            ]],
            'fulfillments' => [[
                'id' => 456,
                'admin_graphql_api_id' => 'gid://shopify/Fulfillment/456',
                'created_at' => '2026-08-31T08:00:00Z',
                'status' => 'success',
                'display_status' => 'delivered',
                'shipment_status' => 'delivered',
                'tracking_company' => 'DHL',
                'tracking_number' => 'TRACK-1',
                'tracking_url' => 'https://tracking.example/TRACK-1',
                'tracking_numbers' => ['TRACK-1'],
                'tracking_urls' => ['https://tracking.example/TRACK-1'],
                'line_items' => [[
                    'id' => 987,
                    'title' => 'Blue Widget',
                    'name' => 'Blue Widget - Large',
                    'sku' => 'WIDGET-BLUE-L',
                    'quantity' => 2,
                    'variant_title' => 'Large',
                    'price' => '39.95',
                    'admin_graphql_api_id' => 'gid://shopify/LineItem/987',
                ]],
            ]],
            'refunds' => [[
                'id' => 654,
                'admin_graphql_api_id' => 'gid://shopify/Refund/654',
                'created_at' => '2026-09-02T11:30:00Z',
                'note' => 'Customer return',
                'total_refunded' => '39.95',
                'refund_line_items' => [],
                'transactions' => [[
                    'id' => 321,
                    'kind' => 'refund',
                    'status' => 'success',
                    'amount' => '39.95',
                    'admin_graphql_api_id' => 'gid://shopify/OrderTransaction/321',
                ]],
            ]],
        ], $order);
    }

    public function test_preserves_legacy_defaults_for_incomplete_components(): void
    {
        $order = (new ShopifyOrderNormalizer)->normalize([
            'id' => 'gid://shopify/Order/123',
            'name' => 'CUSTOM-A',
            'shippingAddress' => [],
            'lineItems' => ['nodes' => [[]]],
            'fulfillments' => [[]],
        ]);

        $this->assertSame(123, $order['id']);
        $this->assertSame('CUSTOM-A', $order['order_number']);
        $this->assertSame('', $order['shipping_address']['address1']);
        $this->assertSame('', $order['line_items'][0]['sku']);
        $this->assertSame(0, $order['line_items'][0]['quantity']);
        $this->assertSame('', $order['fulfillments'][0]['status']);
        $this->assertSame([], $order['fulfillments'][0]['tracking_numbers']);
        $this->assertSame([], $order['fulfillments'][0]['line_items']);
    }

    public function test_rejects_malformed_tags_without_returning_a_partial_order(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Shopify returned an invalid tags collection.');

        (new ShopifyOrderNormalizer)->normalize(['tags' => 'vip']);
    }

    public function test_rejects_malformed_fulfillment_line_items_without_returning_a_partial_order(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Shopify returned an invalid fulfillment line item.');

        (new ShopifyOrderNormalizer)->normalize([
            'fulfillments' => [[
                'fulfillmentLineItems' => ['edges' => [['node' => ['quantity' => 1]]]],
            ]],
        ]);
    }

    public function test_rejects_malformed_refund_transactions_without_returning_a_partial_order(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Shopify returned an invalid refund transaction.');

        (new ShopifyOrderNormalizer)->normalize([
            'refunds' => [[
                'transactions' => ['nodes' => ['invalid']],
            ]],
        ]);
    }

    public function test_selects_high_as_the_highest_risk_among_lower_assessments(): void
    {
        $order = (new ShopifyOrderNormalizer)->normalize([
            'risk' => [
                'recommendation' => 'CANCEL',
                'assessments' => [
                    ['riskLevel' => 'LOW'],
                    ['riskLevel' => 'HIGH'],
                    ['riskLevel' => 'MEDIUM'],
                ],
            ],
        ]);

        $this->assertSame('HIGH', $order['risk_level']);
        $this->assertSame('CANCEL', $order['risk_recommendation']);
        $this->assertSame([
            ['riskLevel' => 'LOW'],
            ['riskLevel' => 'HIGH'],
            ['riskLevel' => 'MEDIUM'],
        ], $order['risk_assessments']);
    }

    public function test_returns_legacy_risk_defaults_for_empty_assessments(): void
    {
        $order = (new ShopifyOrderNormalizer)->normalize([
            'risk' => [
                'recommendation' => null,
                'assessments' => [],
            ],
        ]);

        $this->assertSame('', $order['risk_level']);
        $this->assertSame('', $order['risk_recommendation']);
        $this->assertSame([], $order['risk_assessments']);
    }

    public function test_rejects_malformed_risk_assessments_without_returning_a_partial_order(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Shopify returned an invalid risk assessment.');

        (new ShopifyOrderNormalizer)->normalize([
            'risk' => ['assessments' => ['HIGH']],
        ]);
    }

    public function test_includes_discount_codes_when_present(): void
    {
        $order = (new ShopifyOrderNormalizer)->normalize([
            'discountApplications' => ['nodes' => [[
                '__typename' => 'DiscountCodeApplication',
                'code' => 'DEAL10',
                'allocationMethod' => 'ACROSS',
                'targetSelection' => 'ALL',
                'targetType' => 'LINE_ITEM',
                'value' => ['__typename' => 'MoneyV2', 'amount' => '10.00'],
            ]]],
        ]);

        $this->assertCount(1, $order['discount_codes']);
        $this->assertSame(['code' => 'DEAL10', 'amount' => '10.00', 'type' => 'fixed_amount', 'allocation_method' => 'across', 'target_selection' => 'all', 'target_type' => 'line_item'], $order['discount_codes'][0]);
    }

    public function test_filters_out_non_discount_code_applications(): void
    {
        $order = (new ShopifyOrderNormalizer)->normalize([
            'discountApplications' => ['nodes' => [['__typename' => 'AutomaticDiscountApplication', 'code' => 'AUTO']]],
        ]);

        $this->assertSame([], $order['discount_codes']);
    }

    public function test_includes_note_attributes_when_present(): void
    {
        $order = (new ShopifyOrderNormalizer)->normalize([
            'customAttributes' => [
                ['key' => 'checkout_session_id', 'value' => '87823702-7746-4191'],
                ['key' => 'bsure-attribute', 'value' => 'Plug Type: Type B (US)'],
            ],
        ]);

        $this->assertCount(2, $order['note_attributes']);
        $this->assertSame(['key' => 'checkout_session_id', 'value' => '87823702-7746-4191'], $order['note_attributes'][0]);
    }

    public function test_note_attributes_is_an_empty_array_when_no_custom_attributes(): void
    {
        $order = (new ShopifyOrderNormalizer)->normalize(['customAttributes' => []]);

        $this->assertSame([], $order['note_attributes']);
    }

    public function test_includes_customer_journey_when_present(): void
    {
        $order = (new ShopifyOrderNormalizer)->normalize([
            'customerJourneySummary' => [
                'daysToConversion' => 3,
                'firstVisit' => ['landingPage' => '/products/widget', 'referrerUrl' => 'https://google.com', 'source' => 'google', 'utmParameters' => ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'summer']],
                'lastVisit' => ['landingPage' => '/cart', 'referrerUrl' => null, 'source' => 'direct'],
            ],
        ]);

        $this->assertSame(3, $order['customer_journey']['days_to_conversion']);
        $this->assertSame('/products/widget', $order['customer_journey']['first_visit']['landing_page']);
        $this->assertSame('google', $order['customer_journey']['first_visit']['utm']['source']);
        $this->assertSame('direct', $order['customer_journey']['last_visit']['source']);
        $this->assertSame('', $order['customer_journey']['last_visit']['utm']['source']);
    }

    public function test_includes_source_name_and_app_when_present(): void
    {
        $order = (new ShopifyOrderNormalizer)->normalize(['sourceName' => 'web', 'app' => ['name' => 'Online Store']]);

        $this->assertSame('web', $order['source_name']);
        $this->assertSame('Online Store', $order['app_name']);
    }

    public function test_includes_finance_fields_when_present(): void
    {
        $order = (new ShopifyOrderNormalizer)->normalize([
            'currentTotalPriceSet' => ['shopMoney' => ['amount' => '89.00']],
            'edited' => true,
            'paymentGatewayNames' => ['shopify_payments', 'manual'],
            'poNumber' => 'PO-42',
        ]);

        $this->assertSame('89.00', $order['current_total_price']);
        $this->assertTrue($order['edited']);
        $this->assertSame(['shopify_payments', 'manual'], $order['payment_gateway_names']);
        $this->assertSame('PO-42', $order['po_number']);
    }

    public function test_includes_support_fields_when_present(): void
    {
        $order = (new ShopifyOrderNormalizer)->normalize([
            'confirmationNumber' => 'ABC123XYZ',
            'statusPageUrl' => 'https://shop.example/orders/abc/status',
            'customerLocale' => 'en-US',
        ]);

        $this->assertSame('ABC123XYZ', $order['confirmation_number']);
        $this->assertSame('https://shop.example/orders/abc/status', $order['status_page_url']);
        $this->assertSame('en-US', $order['customer_locale']);
    }

    public function test_includes_test_flag_when_present(): void
    {
        $order = (new ShopifyOrderNormalizer)->normalize(['test' => true]);

        $this->assertTrue($order['test']);
    }

    public function test_attribution_fields_are_absent_when_not_requested(): void
    {
        $order = (new ShopifyOrderNormalizer)->normalize(['id' => 'gid://shopify/Order/1']);

        foreach (['discount_codes', 'note_attributes', 'customer_journey', 'source_name', 'app_name', 'current_total_price', 'edited', 'test', 'payment_gateway_names', 'po_number', 'confirmation_number', 'status_page_url', 'customer_locale'] as $field) {
            $this->assertArrayNotHasKey($field, $order);
        }
    }
}
