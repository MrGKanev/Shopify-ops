<?php

namespace Tests\Feature\Integrations\Shopify;

use App\Integrations\Shopify\ShopifyOrderEventNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShopifyOrderEventNormalizerTest extends TestCase
{
    public function test_returns_the_legacy_event_shape(): void
    {
        $event = (new ShopifyOrderEventNormalizer)->normalize([
            'id' => 'gid://shopify/OrderEvent/987',
            'action' => 'FULFILLMENT_SUCCESS',
            'appTitle' => 'Shopify Flow',
            'createdAt' => '2026-09-05T12:30:00Z',
            'message' => 'Fulfillment completed',
            'subjectId' => 'gid://shopify/Order/123',
            'subjectType' => 'ORDER',
        ], 'gid://shopify/Order/456');

        $this->assertSame([
            'id' => 987,
            'admin_graphql_api_id' => 'gid://shopify/OrderEvent/987',
            'verb' => 'fulfillment_success',
            'action' => 'fulfillment_success',
            'created_at' => '2026-09-05T12:30:00Z',
            'message' => 'Fulfillment completed',
            'subject_id' => 123,
            'subject_type' => 'order',
            'subject_graphql_api_id' => 'gid://shopify/Order/123',
            'app_title' => 'Shopify Flow',
        ], $event);
    }

    public function test_uses_the_order_as_the_subject_for_non_basic_events(): void
    {
        $event = (new ShopifyOrderEventNormalizer)->normalize([
            'id' => 'gid://shopify/OrderEvent/987',
            'action' => 'CONFIRMED',
            'createdAt' => '2026-09-05T12:30:00Z',
            'message' => 'Order confirmed',
        ], 'gid://shopify/Order/456');

        $this->assertSame(456, $event['subject_id']);
        $this->assertSame('order', $event['subject_type']);
        $this->assertSame('gid://shopify/Order/456', $event['subject_graphql_api_id']);
        $this->assertSame('', $event['app_title']);
    }

    #[DataProvider('addressChangeEvents')]
    public function test_is_address_change_event(array $event, bool $expected): void
    {
        $this->assertSame($expected, (new ShopifyOrderEventNormalizer)->isAddressChangeEvent($event));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: bool}> */
    public static function addressChangeEvents(): array
    {
        return [
            'shipping address in message' => [['verb' => '', 'action' => '', 'message' => 'The shipping address was updated.'], true],
            'address was phrase' => [['verb' => '', 'action' => '', 'message' => 'The address was changed to 123 Main St.'], true],
            'shipping_address_updated verb' => [['verb' => 'shipping_address_updated', 'action' => '', 'message' => ''], true],
            'shipping_address underscore in message' => [['verb' => '', 'action' => '', 'message' => 'Field shipping_address updated.'], true],
            'unrelated message' => [['verb' => 'placed', 'action' => 'placed', 'message' => 'Order was placed by customer.'], false],
            'empty event' => [[], false],
            'case insensitive' => [['verb' => '', 'action' => '', 'message' => 'SHIPPING ADDRESS was updated.'], true],
        ];
    }

    #[DataProvider('orderEditEvents')]
    public function test_is_order_edit_event(array $event, bool $expected): void
    {
        $this->assertSame($expected, (new ShopifyOrderEventNormalizer)->isOrderEditEvent($event));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: bool}> */
    public static function orderEditEvents(): array
    {
        return [
            'edit_complete verb' => [['verb' => 'edit_complete', 'message' => ''], true],
            'was edited message' => [['verb' => 'comment', 'message' => 'This order was edited by staff.'], true],
            'were edited message' => [['verb' => 'comment', 'message' => 'Line items were edited.'], true],
            'item was added' => [['verb' => 'comment', 'message' => 'An item was added to the order.'], true],
            'item was removed' => [['verb' => 'comment', 'message' => '1 item was removed from the order.'], true],
            'discount was added' => [['verb' => 'comment', 'message' => 'A discount was added.'], true],
            'discount was removed' => [['verb' => 'comment', 'message' => 'A discount was removed from the order.'], true],
            'note was updated' => [['verb' => 'comment', 'message' => 'The note was updated.'], true],
            'custom attributes' => [['verb' => 'comment', 'message' => 'Custom attributes were changed.'], true],
            'unrelated placed event' => [['verb' => 'placed', 'message' => 'Order was placed.'], false],
            'empty event' => [[], false],
            'case insensitive message' => [['verb' => '', 'message' => 'THE ORDER WAS EDITED BY ADMIN.'], true],
            'address update via edit_complete is not also an edit' => [['verb' => 'edit_complete', 'action' => 'edit_complete', 'message' => 'Shipping address was updated to 1 Main St.'], false],
            'non-address edit_complete is still an edit' => [['verb' => 'edit_complete', 'action' => 'edit_complete', 'message' => 'An item was added to the order.'], true],
        ];
    }
}
