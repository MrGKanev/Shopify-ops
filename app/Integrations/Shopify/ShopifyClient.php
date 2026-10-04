<?php

namespace App\Integrations\Shopify;

use App\Integrations\Shopify\Exceptions\ShopifyGraphqlException;
use App\Models\Store;
use Closure;
use Generator;
use InvalidArgumentException;

class ShopifyClient
{
    public const API_VERSION = ShopifyGraphqlTransport::API_VERSION;

    private readonly ShopifyNestedConnections $nestedConnections;

    /**
     * The normalizer keeps the migration boundary compatible with existing workflows.
     */
    public function __construct(
        private readonly Store $store,
        private readonly ShopifyOrderNormalizer $orderNormalizer,
        private readonly ShopifyOrderEventNormalizer $orderEventNormalizer,
        private readonly ShopifyGraphqlTransport $transport = new ShopifyGraphqlTransport,
        private readonly ShopifyPaymentNormalizer $paymentNormalizer = new ShopifyPaymentNormalizer,
    ) {
        $this->nestedConnections = new ShopifyNestedConnections($this->transport);
    }

    /** @return array{shop_name: string, timezone: string, scopes: list<string>, requested_version: string, returned_version: string} */
    public function healthCheck(): array
    {
        $result = $this->graphql(ShopifyQueries::get('ShopifyHealth'));
        $shop = $result['data']['shop'] ?? null;
        $accessScopes = $result['data']['currentAppInstallation']['accessScopes'] ?? null;

        if (! is_array($shop) || ! is_array($accessScopes)) {
            throw new ShopifyGraphqlException([], 'Shopify health check returned an unexpected response shape.');
        }

        $scopes = [];
        foreach ($accessScopes as $scope) {
            if (is_array($scope) && is_string($scope['handle'] ?? null) && trim($scope['handle']) !== '') {
                $scopes[] = trim($scope['handle']);
            }
        }

        return [
            'shop_name' => is_scalar($shop['name'] ?? null) ? trim((string) $shop['name']) : '',
            'timezone' => is_string($shop['ianaTimezone'] ?? null) ? trim($shop['ianaTimezone']) : '',
            'scopes' => array_values(array_unique($scopes)),
            'requested_version' => self::API_VERSION,
            'returned_version' => $this->transport->lastResponseApiVersion(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByOrderNumber(string $orderNumber): array
    {
        $cleanOrderNumber = ltrim(trim($orderNumber), '#');

        if ($cleanOrderNumber === '' || preg_match('/\A[a-zA-Z0-9_-]+\z/', $cleanOrderNumber) !== 1) {
            throw new InvalidArgumentException('The Shopify order number is invalid.');
        }

        $result = $this->graphql(ShopifyQueries::get('FindOrderByName'), ['query' => "name:{$cleanOrderNumber}"]);
        $edges = $result['data']['orders']['edges'] ?? null;

        if (! is_array($edges)) {
            throw new ShopifyGraphqlException([], 'Shopify order lookup returned an unexpected response shape.');
        }

        $orders = [];

        foreach ($edges as $edge) {
            if (! is_array($edge) || ! is_array($edge['node'] ?? null)) {
                throw new ShopifyGraphqlException([], 'Shopify order lookup returned an unexpected response shape.');
            }

            $orders[] = $this->orderNormalizer->normalize($this->nestedConnections->completeLineItems($this->store, $edge['node']));
        }

        return $orders;
    }

    /**
     * @param  list<mixed>  $orderNumbers
     * @return array<int|string, list<array<string, mixed>>>
     */
    public function findByOrderNumbers(array $orderNumbers): array
    {
        $cleanOrderNumbers = [];

        foreach ($orderNumbers as $orderNumber) {
            if (! is_string($orderNumber)) {
                throw new InvalidArgumentException('Every Shopify order number must be a string.');
            }

            $cleanOrderNumber = ltrim(trim($orderNumber), '#');

            if ($cleanOrderNumber === '') {
                continue;
            }

            if (mb_strlen($cleanOrderNumber) > 64
                || preg_match('/\A[a-zA-Z0-9_-]+\z/', $cleanOrderNumber) !== 1) {
                throw new InvalidArgumentException('A Shopify order number is invalid.');
            }

            $cleanOrderNumbers[$cleanOrderNumber] = true;
        }

        if ($cleanOrderNumbers === []) {
            return [];
        }

        if (count($cleanOrderNumbers) > 50) {
            throw new InvalidArgumentException('Shopify batch lookup accepts at most 50 unique order numbers.');
        }

        $terms = array_map(fn (string $orderNumber): string => "name:{$orderNumber}", array_keys($cleanOrderNumbers));
        $page = $this->paginateGraphql(ShopifyQueries::get('FindOrdersByNames'), 'orders', ['query' => '('.implode(' OR ', $terms).')']);

        if ($page['truncated']) {
            throw new ShopifyGraphqlException([], 'Shopify batch order lookup exceeded its page limit.');
        }

        $ordersByNumber = array_fill_keys(array_keys($cleanOrderNumbers), []);

        foreach ($page['edges'] as $edge) {
            if (! is_array($edge['node'] ?? null) || ! is_string($edge['node']['name'] ?? null)) {
                throw new ShopifyGraphqlException([], 'Shopify batch order lookup returned an unexpected response shape.');
            }

            $cleanReturnedNumber = ltrim(trim($edge['node']['name']), '#');

            if (array_key_exists($cleanReturnedNumber, $ordersByNumber)) {
                $ordersByNumber[$cleanReturnedNumber][] = $this->orderNormalizer->normalize($edge['node']);
            }
        }

        return $ordersByNumber;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getOrderEvents(string $orderId): array
    {
        $graphqlOrderId = $this->orderGid($orderId);

        return array_map(
            fn (array $node): array => $this->orderEventNormalizer->normalize($node, $graphqlOrderId),
            $this->nestedConnections->orderEvents($this->store, $graphqlOrderId),
        );
    }

    public function updateOrderNote(string $orderId, string $note): void
    {
        $result = $this->graphql(ShopifyQueries::get('UpdateOrderNote'), ['id' => $this->orderGid($orderId), 'note' => $note]);
        $userErrors = $result['data']['orderUpdate']['userErrors'] ?? null;

        if (! is_array($userErrors)) {
            throw new ShopifyGraphqlException([], 'Shopify orderUpdate returned an unexpected response shape.');
        }

        if ($userErrors !== []) {
            $messages = array_map(
                fn (mixed $error): string => is_array($error) && is_scalar($error['message'] ?? null) ? (string) $error['message'] : 'Unknown error',
                $userErrors,
            );
            throw new ShopifyGraphqlException([], 'Shopify orderUpdate error: '.implode('; ', $messages));
        }
    }

    /**
     * @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool}
     */
    public function searchOrdersByTag(string $tag, ?string $startDate = null, ?string $endDate = null): array
    {
        return $this->paginateOrderNodes('SearchOrdersByTag', OrderSearch::make()->tagged($tag)->createdBetween($this->store, $startDate, $endDate), 20);
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function highValueOrderCandidates(string $startDate, string $endDate): array
    {
        return $this->paginateOrderNodes('HighValueOrderCandidates', OrderSearch::anyStatus()->paidOrPartiallyPaid()->unfulfilledOrPartial()->createdBetween($this->store, $startDate, $endDate));
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function countryMismatchCandidates(string $startDate, string $endDate): array
    {
        return $this->paginateOrderNodes('CountryMismatchCandidates', OrderSearch::anyStatus()->paidOrPartiallyPaid()->createdBetween($this->store, $startDate, $endDate));
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function taxAuditCandidates(string $startDate, string $endDate): array
    {
        return $this->paginateOrderNodes('TaxAuditCandidates', $this->paidOrdersCreated($startDate, $endDate), map: fn (array $node): array => [
            ...$this->orderNormalizer->normalize($node),
            'total_tax' => $node['totalTaxSet']['shopMoney']['amount'] ?? '0',
            'customer_tax_exempt' => ($node['customer']['taxExempt'] ?? false) === true,
        ]);
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function consentAuditCandidates(string $startDate, string $endDate): array
    {
        return $this->paginateOrderNodes('ConsentAuditCandidates', $this->paidOrdersCreated($startDate, $endDate), map: function (array $node): array {
            $customer = is_array($node['customer'] ?? null) ? $node['customer'] : [];

            return [
                ...$this->orderNormalizer->normalize($node),
                'customer_email_consent' => is_scalar($customer['emailMarketingConsent']['marketingState'] ?? null) ? strtolower((string) $customer['emailMarketingConsent']['marketingState']) : '',
                'customer_sms_consent' => is_scalar($customer['smsMarketingConsent']['marketingState'] ?? null) ? strtolower((string) $customer['smsMarketingConsent']['marketingState']) : '',
            ];
        });
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function fraudRiskCandidates(string $startDate, string $endDate): array
    {
        return $this->paginateOrderNodes('FraudRiskCandidates', $this->paidOrdersCreated($startDate, $endDate));
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function emailCheckCandidates(string $startDate, string $endDate): array
    {
        return $this->paginateOrderNodes('EmailCheckCandidates', $this->paidOrdersCreated($startDate, $endDate));
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function addressCheckCandidates(string $startDate, string $endDate, bool $unfulfilledOnly): array
    {
        $search = $this->paidOrdersCreated($startDate, $endDate);

        return $this->paginateOrderNodes('AddressCheckCandidates', $unfulfilledOnly ? $search->unfulfilled() : $search, map: fn (array $node): array => [
            ...$this->orderNormalizer->normalize($node),
            'shipping_lines' => $this->listOfArrays($node['shippingLines']['nodes'] ?? [], 'Shopify address check returned invalid shipping lines.'),
        ]);
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function discountAbuseCandidates(string $startDate, string $endDate): array
    {
        return $this->paginateOrderNodes('DiscountAbuseCandidates', $this->paidOrdersCreated($startDate, $endDate), map: function (array $node): array {
            $applications = $this->listOfArrays($node['discountApplications']['nodes'] ?? [], 'Shopify discount abuse report returned invalid discount applications.');
            $codes = array_filter($applications, fn (array $application): bool => ($application['__typename'] ?? '') === 'DiscountCodeApplication');

            return [
                ...$this->orderNormalizer->normalize($node),
                'discount_codes' => array_values(array_map(fn (array $application): array => ['code' => is_scalar($application['code'] ?? null) ? trim((string) $application['code']) : ''], $codes)),
            ];
        });
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function sameIpCandidates(string $startDate, string $endDate): array
    {
        return $this->paginateOrderNodes('SameIpCandidates', $this->paidOrdersCreated($startDate, $endDate), map: fn (array $node): array => [
            ...$this->orderNormalizer->normalize($node),
            'client_ip' => is_scalar($node['clientIp'] ?? null) ? trim((string) $node['clientIp']) : '',
        ]);
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function duplicateOrderCandidates(string $startDate, string $endDate): array
    {
        return $this->paginateOrderNodes('DuplicateOrderCandidates', OrderSearch::created($this->store, $startDate, $endDate), 40);
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function customerLtvCandidates(string $startDate, string $endDate): array
    {
        return $this->paginateOrderNodes('CustomerLtvCandidates', OrderSearch::anyStatus()->createdBetween($this->store, $startDate, $endDate), 1000);
    }

    /** @return array{orders: list<array<string, mixed>>, customer: array<string, mixed>|null, pages: int, truncated: bool} */
    public function customerOrderHistory(string $email): array
    {
        $customer = null;
        $result = $this->paginateOrderNodes('CustomerOrderHistory', OrderSearch::make()->email($email), 20, function (array $node) use (&$customer): array {
            if ($customer === null && isset($node['customer'])) {
                if (! is_array($node['customer'])) {
                    throw new ShopifyGraphqlException([], 'Shopify customer lookup returned an invalid customer.');
                }
                $customer = $node['customer'];
            }

            return $this->orderNormalizer->normalize($node);
        });

        return ['orders' => $result['orders'], 'customer' => $customer, 'pages' => $result['pages'], 'truncated' => $result['truncated']];
    }

    /** @return list<array<string, mixed>> */
    public function orderMetafieldDefinitions(): array
    {
        $edges = $this->graphql(ShopifyQueries::get('OrderMetafieldDefinitions'))['data']['metafieldDefinitions']['edges'] ?? null;

        if (! is_array($edges) || ! array_is_list($edges)) {
            throw new ShopifyGraphqlException([], 'Shopify metafield definitions returned an unexpected response shape.');
        }

        return array_map(function (mixed $edge): array {
            if (! is_array($edge) || ! is_array($edge['node'] ?? null)) {
                throw new ShopifyGraphqlException([], 'Shopify metafield definitions returned an invalid definition.');
            }

            return $edge['node'];
        }, $edges);
    }

    /** @return array{orders: list<array<string, mixed>>, scanned: int, with_metafield: int, sample_values: list<string>, pages: int, truncated: bool} */
    public function searchOrdersByMetafield(string $namespace, string $key, string $value, ?string $startDate, ?string $endDate): array
    {
        $scanned = $withMetafield = 0;
        $samples = [];
        $search = OrderSearch::created($this->store, $startDate ?: null, $endDate ?: null);
        $result = $this->paginateOrderNodes('SearchOrdersByMetafield', $search, 10, function (array $node) use ($value, &$scanned, &$withMetafield, &$samples): ?array {
            $scanned++;
            $metafield = $node['metafield'] ?? null;
            if ($metafield !== null && ! is_array($metafield)) {
                throw new ShopifyGraphqlException([], 'Shopify metafield search returned an invalid metafield.');
            }
            $metafieldValue = is_scalar($metafield['value'] ?? null) ? (string) $metafield['value'] : null;
            if ($metafieldValue === null) {
                return null;
            }
            $withMetafield++;
            if (count($samples) < 5 && ! in_array($metafieldValue, $samples, true)) {
                $samples[] = $metafieldValue;
            }
            if ($value !== '' && mb_stripos($metafieldValue, $value) === false) {
                return null;
            }

            return [...$this->orderNormalizer->normalize($node), 'metafield' => ['value' => $metafieldValue, 'type' => is_scalar($metafield['type'] ?? null) ? (string) $metafield['type'] : '']];
        }, ['namespace' => $namespace, 'key' => $key]);

        return ['orders' => $result['orders'], 'scanned' => $scanned, 'with_metafield' => $withMetafield, 'sample_values' => $samples, 'pages' => $result['pages'], 'truncated' => $result['truncated']];
    }

    /**
     * @param  list<int|string>  $orderIds
     * @return array<string, list<array<string, mixed>>>
     */
    public function orderMetafields(array $orderIds): array
    {
        $query = ShopifyQueries::get('OrderMetafields');
        $output = [];
        foreach (array_values(array_unique(array_map('strval', $orderIds))) as $id) {
            $output[$id] = [];
            $cursor = null;
            do {
                $result = $this->graphql($query, ['id' => str_starts_with($id, 'gid://') ? $id : "gid://shopify/Order/{$id}", 'after' => $cursor]);
                $connection = $result['data']['order']['metafields'] ?? null;
                if (! is_array($connection) || ! is_array($connection['nodes'] ?? null)) {
                    throw new ShopifyGraphqlException([], 'Shopify order metafields returned an unexpected response shape.');
                }
                foreach ($connection['nodes'] as $node) {
                    if (! is_array($node)) {
                        throw new ShopifyGraphqlException([], 'Shopify order metafields returned an invalid metafield.');
                    }
                    $metafieldId = is_scalar($node['id'] ?? null) ? (string) $node['id'] : '';
                    $output[$id][] = ['id' => ctype_digit(basename($metafieldId)) ? (int) basename($metafieldId) : 0, 'namespace' => $node['namespace'] ?? '', 'key' => $node['key'] ?? '', 'value' => $node['value'] ?? '', 'type' => $node['type'] ?? '', 'owner_id' => ctype_digit($id) ? (int) $id : $id, 'owner_resource' => 'order', 'created_at' => $node['createdAt'] ?? '', 'updated_at' => $node['updatedAt'] ?? '', 'admin_graphql_api_id' => $metafieldId];
                }
                $cursor = is_scalar($connection['pageInfo']['endCursor'] ?? null) ? (string) $connection['pageInfo']['endCursor'] : null;
            } while (($connection['pageInfo']['hasNextPage'] ?? false) && $cursor !== null);
        }

        return $output;
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function tagPolicyCandidates(string $startDate, string $endDate): array
    {
        return $this->paginateOrderNodes('TagPolicyCandidates', $this->paidOrdersCreated($startDate, $endDate));
    }

    /** @return array{disputes: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function openDisputes(): array
    {
        $result = $this->paginateGraphql(ShopifyQueries::get('OpenDisputes'), 'disputes', ['search' => 'status:NEEDS_RESPONSE OR status:UNDER_REVIEW'], 20);
        $disputes = [];
        foreach ($this->nodes($result['edges'], 'OpenDisputes') as $node) {
            $disputes[] = $this->paymentNormalizer->dispute($node);
        }

        return ['disputes' => $disputes, 'pages' => $result['pages'], 'truncated' => $result['truncated']];
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function noteFlagCandidates(string $startDate, string $endDate): array
    {
        return $this->paginateOrderNodes('NoteFlagCandidates', OrderSearch::anyStatus()->paid()->unfulfilled()->createdBetween($this->store, $startDate, $endDate));
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function repeatRefundCandidates(string $startDate, string $endDate): array
    {
        return $this->refundCandidates(OrderSearch::anyStatus()->refundedOrPartiallyRefunded()->createdBetween($this->store, $startDate, $endDate));
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function refundTrackerCandidates(string $startDate, string $endDate): array
    {
        return $this->repeatRefundCandidates($startDate, $endDate);
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function returnedItemCandidates(string $startDate): array
    {
        return $this->refundCandidates(OrderSearch::anyStatus()->refundedOrPartiallyRefunded()->updatedSince($this->store, $startDate));
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function fulfilledItemCandidates(string $startDate): array
    {
        return $this->paginateOrderNodes('FulfilledItemCandidates', OrderSearch::anyStatus()->updatedSince($this->store, $startDate));
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function shippingMarginCandidates(string $startDate): array
    {
        return $this->paginateOrderNodes('ShippingMarginCandidates', OrderSearch::anyStatus()->fulfilledOrPartial()->updatedSince($this->store, $startDate), map: function (array $node): array {
            $shippingLines = $this->listOfArrays($node['shippingLines']['nodes'] ?? null, 'Shopify shipping margin report returned an unexpected response shape.');

            return [...$this->orderNormalizer->normalize($node), 'shipping_lines' => array_map(fn (array $line): array => [
                'title' => is_scalar($line['title'] ?? null) ? (string) $line['title'] : '',
                'price' => is_numeric($line['originalPriceSet']['shopMoney']['amount'] ?? null) ? (string) $line['originalPriceSet']['shopMoney']['amount'] : '0.00',
            ], $shippingLines)];
        });
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function fulfillmentSlaCandidates(string $startDate, string $endDate): array
    {
        return $this->paginateOrderNodes('FulfillmentSlaCandidates', $this->paidOrdersCreated($startDate, $endDate), map: fn (array $node): array => $this->withShippingLines($node, 'Shopify fulfillment SLA report returned an unexpected response shape.'));
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function partialFulfillmentCandidates(string $startDate, string $endDate): array
    {
        $search = OrderSearch::openStatus()->notRefunded()->partiallyFulfilled()->createdBetween($this->store, $startDate, $endDate);

        return $this->paginateOrderNodes('PartialFulfillmentCandidates', $search, map: function (array $node): ?array {
            $order = $this->orderNormalizer->normalize($node);

            return $order['fulfillment_status'] === 'partial' ? $order : null;
        });
    }

    /** @return array{fulfillment_orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function onHoldFulfillmentCandidates(string $startDate, string $endDate): array
    {
        $result = $this->paginateGraphql(ShopifyQueries::get('OnHoldFulfillmentCandidates'), 'fulfillmentOrders', [], 100);
        $nodes = [];
        foreach ($this->nodes($result['edges'], 'OnHoldFulfillmentCandidates') as $node) {
            $order = $node['order'] ?? null;
            if (! is_array($order)) {
                throw new ShopifyGraphqlException([], 'Shopify OnHoldFulfillmentCandidates returned an unexpected response shape.');
            }
            $orderDate = is_scalar($order['createdAt'] ?? null) ? substr((string) $order['createdAt'], 0, 10) : '';
            if ($orderDate >= $startDate && $orderDate <= $endDate) {
                $nodes[] = $node;
            }
        }

        return ['fulfillment_orders' => $nodes, 'pages' => $result['pages'], 'truncated' => $result['truncated']];
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function noTrackingCandidates(string $startDate): array
    {
        return $this->paginateOrderNodes('NoTrackingCandidates', OrderSearch::anyStatus()->updatedSince($this->store, $startDate), map: function (array $node): ?array {
            $order = $this->orderNormalizer->normalize($node);

            return in_array($order['fulfillment_status'], ['fulfilled', 'partial'], true) ? $order : null;
        });
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function itemMismatchCandidates(string $startDate, string $endDate): array
    {
        return $this->paginateOrderNodes('ItemMismatchCandidates', OrderSearch::anyStatus()->createdBetween($this->store, $startDate, $endDate), map: fn (array $node): array => $this->withShippingLines($node, 'Shopify item mismatch report returned invalid shipping lines.'));
    }

    /** @return array{events: list<array<string, mixed>>, orders: array<string, array<string, mixed>>, pages: int, truncated: bool} */
    public function orderEditCandidates(string $startDate, string $endDate): array
    {
        $page = $this->paginateGraphql(ShopifyQueries::get('OrderEditEvents'), 'events', ['search' => OrderSearch::created($this->store, $startDate, $endDate)->toString()], 100);
        $events = $ids = [];
        foreach ($this->nodes($page['edges'], 'OrderEditEvents') as $node) {
            $event = $this->orderEventNormalizer->normalize($node, '');
            $event['verb'] = mb_strtolower(is_scalar($node['action'] ?? null) ? (string) $node['action'] : '');
            $events[] = $event;
            if ($this->orderEventNormalizer->isOrderEditEvent($event) && is_int($event['subject_id']) && $event['subject_id'] > 0) {
                $ids[(string) $event['subject_id']] = true;
            }
        }

        return ['events' => $events, 'orders' => $this->ordersByIds('EditedOrders', array_keys($ids)), 'pages' => $page['pages'], 'truncated' => $page['truncated']];
    }

    /** @return array{events: list<array<string, mixed>>, orders: array<string, array<string, mixed>>, pages: int, truncated: bool} */
    public function addressChangeCandidates(string $startDate, string $endDate): array
    {
        $page = $this->paginateGraphql(ShopifyQueries::get('AddressChangeEvents'), 'events', ['search' => OrderSearch::created($this->store, $startDate, $endDate)->toString()], 100);
        $events = $ids = [];
        foreach ($this->nodes($page['edges'], 'AddressChangeEvents') as $node) {
            $event = $this->orderEventNormalizer->normalize($node, '');
            $haystack = mb_strtolower(trim((string) ($event['verb'] ?? '').' '.(string) ($event['message'] ?? '')));
            if (! str_contains($haystack, 'shipping address') && ! str_contains($haystack, 'address was') && ! str_contains($haystack, 'shipping_address')) {
                continue;
            }
            $events[] = $event;
            if (is_int($event['subject_id']) && $event['subject_id'] > 0) {
                $ids[(string) $event['subject_id']] = true;
            }
        }

        return ['events' => $events, 'orders' => $this->ordersByIds('AddressChangedOrders', array_keys($ids)), 'pages' => $page['pages'], 'truncated' => $page['truncated']];
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function tagAuditCandidates(string $startDate, string $endDate): array
    {
        return $this->paginateOrderNodes('TagAuditCandidates', OrderSearch::anyStatus()->createdBetween($this->store, $startDate, $endDate), map: fn (array $node): array => $node);
    }

    /** @return array{products: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function productCompletenessCandidates(): array
    {
        return $this->catalogueCandidates('status:active');
    }

    /** @return array{products: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function skuDuplicatesCandidates(): array
    {
        return $this->catalogueCandidates(null);
    }

    /** @return array{products: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function inventoryOversellCandidates(): array
    {
        return $this->catalogueCandidates('status:active');
    }

    /** @return array{products: list<array<string, mixed>>, orders: list<array<string, mixed>>, product_pages: int, order_pages: int, products_truncated: bool, orders_truncated: bool} */
    public function inventoryAgingCandidates(string $startDate, string $endDate): array
    {
        $products = $this->catalogueCandidates('status:active');
        $search = OrderSearch::anyStatus()->paidOrPartiallyPaid()->createdBetween($this->store, $startDate, $endDate);
        $orders = $this->paginateOrderNodes('InventoryAgingOrders', $search, map: fn (array $node): array => $this->orderNormalizer->normalize($this->nestedConnections->completeLineItems($this->store, $node)));

        return ['products' => $products['products'], 'orders' => $orders['orders'], 'product_pages' => $products['pages'], 'order_pages' => $orders['pages'], 'products_truncated' => $products['truncated'], 'orders_truncated' => $orders['truncated']];
    }

    /** @return array{products: list<array<string, mixed>>, orders: list<array<string, mixed>>, product_pages: int, order_pages: int, products_truncated: bool, orders_truncated: bool} */
    public function inventoryForecastCandidates(string $startDate, string $endDate): array
    {
        return $this->inventoryAgingCandidates($startDate, $endDate);
    }

    /** @return array{products: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function zombieProductsCandidates(): array
    {
        return $this->catalogueCandidates('status:active');
    }

    /** @return array{products: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function catalogQualityCandidates(): array
    {
        return $this->catalogueCandidates('status:active');
    }

    /** @return array{gift_cards: list<array<string, mixed>>, pages: int, truncated: bool} */
    public function giftCardCandidates(): array
    {
        $result = $this->paginateGraphql(ShopifyQueries::get('GiftCardCandidates'), 'giftCards', maxPages: 1000);
        $giftCards = [];

        foreach ($this->nodes($result['edges'], 'GiftCardCandidates') as $node) {
            $giftCards[] = $this->paymentNormalizer->giftCard($node);
        }

        return ['gift_cards' => $giftCards, 'pages' => $result['pages'], 'truncated' => $result['truncated']];
    }

    /**
     * @param  array<string, bool|int|string>  $query
     * @return array<string, mixed>
     */
    public function get(string $resource, array $query = []): array
    {
        return $this->transport->get($this->store, $resource, $query);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphql(string $query, array $variables = []): array
    {
        return $this->transport->graphql($this->store, $query, $variables);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{edges: list<array<string, mixed>>, pages: int, truncated: bool}
     */
    public function paginateGraphql(string $query, string $rootKey, array $variables = [], int $maxPages = 20): array
    {
        return $this->transport->paginateGraphql($this->store, $query, $rootKey, $variables, $maxPages);
    }

    /**
     * Pages through an `orders` connection, validating each edge and mapping its node to a report row.
     *
     * @param  string  $operation  The GraphQL document in resources/graphql/shopify; it receives `$search` and `$after`.
     * @param  (Closure(array<string, mixed>): (array<string, mixed>|null))|null  $map  Builds a row from a raw order node, or null to skip it; defaults to the normalized order.
     * @param  array<string, mixed>  $variables  Extra GraphQL variables sent after `search`.
     * @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool}
     */
    private function paginateOrderNodes(string $operation, OrderSearch $search, int $maxPages = 100, ?Closure $map = null, array $variables = []): array
    {
        $result = $this->paginateGraphql(ShopifyQueries::get($operation), 'orders', ['search' => $search->isEmpty() ? null : $search->toString(), ...$variables], $maxPages);
        $orders = [];

        foreach ($this->nodes($result['edges'], $operation) as $node) {
            $order = $map === null ? $this->orderNormalizer->normalize($node) : $map($node);

            if ($order !== null) {
                $orders[] = $order;
            }
        }

        return ['orders' => $orders, 'pages' => $result['pages'], 'truncated' => $result['truncated']];
    }

    /**
     * Yields each edge's node, failing on the first edge without one.
     *
     * @param  list<array<string, mixed>>  $edges
     * @return Generator<int, array<string, mixed>>
     */
    private function nodes(array $edges, string $operation): Generator
    {
        foreach ($edges as $edge) {
            if (! is_array($edge['node'] ?? null)) {
                throw new ShopifyGraphqlException([], "Shopify {$operation} returned an unexpected response shape.");
            }

            yield $edge['node'];
        }
    }

    private function paidOrdersCreated(string $startDate, string $endDate): OrderSearch
    {
        return OrderSearch::anyStatus()->paid()->createdBetween($this->store, $startDate, $endDate);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function withShippingLines(array $node, string $invalidMessage): array
    {
        $shippingLines = $this->listOfArrays($node['shippingLines']['nodes'] ?? null, $invalidMessage);

        return [...$this->orderNormalizer->normalize($node), 'shipping_lines' => $shippingLines];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOfArrays(mixed $value, string $invalidMessage): array
    {
        if (! is_array($value) || ! array_is_list($value) || ! array_all($value, fn (mixed $item): bool => is_array($item))) {
            throw new ShopifyGraphqlException([], $invalidMessage);
        }

        /** @var list<array<string, mixed>> $value */
        return $value;
    }

    /**
     * Loads orders by legacy id through a `nodes(ids:)` document, keyed by the normalized order id.
     *
     * @param  list<int|string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function ordersByIds(string $operation, array $ids): array
    {
        $orders = [];

        foreach (array_chunk($ids, 250) as $chunk) {
            $graphqlIds = array_map(fn (int|string $id): string => 'gid://shopify/Order/'.(string) $id, $chunk);
            $nodes = $this->graphql(ShopifyQueries::get($operation), ['ids' => $graphqlIds])['data']['nodes'] ?? null;

            if (! is_array($nodes)) {
                throw new ShopifyGraphqlException([], "Shopify {$operation} returned an unexpected response shape.");
            }

            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    throw new ShopifyGraphqlException([], "Shopify {$operation} returned an unexpected response shape.");
                }

                $order = $this->orderNormalizer->normalize($node);
                $id = (string) ($order['id'] ?? '');

                if ($id !== '') {
                    $orders[$id] = $order;
                }
            }
        }

        return $orders;
    }

    /** @return array{orders: list<array<string, mixed>>, pages: int, truncated: bool} */
    private function refundCandidates(OrderSearch $search): array
    {
        return $this->paginateOrderNodes('RepeatRefundCandidates', $search, map: function (array $node): ?array {
            $order = $this->orderNormalizer->normalize($node);

            if (! in_array($order['financial_status'], ['refunded', 'partially_refunded'], true)) {
                return null;
            }

            $order['refunds'] = $this->paymentNormalizer->refunds($node['refunds'] ?? null);

            return $order;
        });
    }

    /** @return array{products: list<array<string, mixed>>, pages: int, truncated: bool} */
    private function catalogueCandidates(?string $search): array
    {
        $result = $this->paginateGraphql(ShopifyQueries::get('CatalogueCandidates'), 'products', ['search' => $search], maxPages: 100);
        $products = [];

        foreach ($this->nodes($result['edges'], 'CatalogueCandidates') as $node) {
            $products[] = $this->nestedConnections->completeProductVariants($this->store, $node);
        }

        return ['products' => $products, 'pages' => $result['pages'], 'truncated' => $result['truncated']];
    }

    private function orderGid(string $orderId): string
    {
        $orderId = trim($orderId);

        if (preg_match('/\Agid:\/\/shopify\/Order\/[0-9]+\z/', $orderId) === 1) {
            return $orderId;
        }

        if ($orderId === '' || ! ctype_digit($orderId)) {
            throw new InvalidArgumentException('The Shopify order ID is invalid.');
        }

        return "gid://shopify/Order/{$orderId}";
    }
}
