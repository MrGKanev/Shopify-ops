<?php

namespace App\Integrations\ShipStation;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;
use UnexpectedValueException;

class ShipStationClient implements ShipStationClientContract
{
    private const string BASE_URL = 'https://ssapi.shipstation.com';

    private const int PAGE_SIZE = 500;

    private const array RETRY_DELAYS_IN_MILLISECONDS = [100, 500, 1000];

    public function __construct(
        #[SensitiveParameter] private readonly string $apiKey,
        #[SensitiveParameter] private readonly string $apiSecret,
    ) {}

    public function healthCheck(): void
    {
        $payload = $this->get('/orders', ['pageSize' => 1]);
        $this->items($payload, 'orders');
    }

    public function findByOrderNumber(string $orderNumber): array
    {
        $payload = $this->get('/orders', [
            'orderNumber' => $orderNumber,
            'pageSize' => 50,
        ]);

        return $this->items($payload, 'orders');
    }

    public function getOrderShipments(string $orderNumber): array
    {
        $payload = $this->get('/shipments', [
            'orderNumber' => $orderNumber,
            'pageSize' => 100,
        ]);

        return $this->items($payload, 'shipments');
    }

    public function fetchAllOrders(string $startDate, string $endDate): array
    {
        $filters = [
            'createDateStart' => $startDate.' 00:00:00',
            'createDateEnd' => $endDate.' 23:59:59',
            'sortBy' => 'OrderDate',
            'sortDir' => 'ASC',
        ];

        return $this->paginate('/orders', $filters, 'orders');
    }

    public function fetchAwaitingOrders(): array
    {
        return $this->paginate('/orders', [
            'orderStatus' => 'awaiting_shipment',
            'sortBy' => 'OrderDate',
            'sortDir' => 'ASC',
        ], 'orders');
    }

    public function fetchActiveOrders(): array
    {
        $orders = [];
        foreach (['awaiting_payment', 'awaiting_shipment', 'on_hold'] as $status) {
            array_push($orders, ...$this->paginate('/orders', ['orderStatus' => $status, 'sortBy' => 'OrderDate', 'sortDir' => 'ASC'], 'orders'));
        }

        return $orders;
    }

    public function fetchShipmentsByDate(string $startDate, string $endDate): array
    {
        return $this->paginate('/shipments', [
            'shipDateStart' => $startDate.' 00:00:00',
            'shipDateEnd' => $endDate.' 23:59:59',
            'sortBy' => 'ShipDate',
            'sortDir' => 'ASC',
        ], 'shipments');
    }

    public function fetchVoidedShipments(string $startDate, string $endDate): array
    {
        return $this->paginate('/shipments', [
            'voidDate_start' => $startDate.' 00:00:00',
            'voidDate_end' => $endDate.' 23:59:59',
        ], 'shipments');
    }

    public function buildOrderPayload(array $shopifyOrder): array
    {
        $address = function (mixed $address): array {
            $address = is_array($address) ? $address : [];
            $name = trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? ''));

            return [
                'name' => $name !== '' ? $name : (is_scalar($address['name'] ?? null) ? (string) $address['name'] : ''),
                'company' => $address['company'] ?? null,
                'street1' => $address['address1'] ?? null,
                'street2' => $address['address2'] ?? null,
                'city' => $address['city'] ?? null,
                'state' => $address['province_code'] ?? $address['province'] ?? null,
                'postalCode' => $address['zip'] ?? null,
                'country' => $address['country_code'] ?? $address['country'] ?? null,
                'phone' => $address['phone'] ?? null,
            ];
        };

        $items = [];

        foreach (is_array($shopifyOrder['line_items'] ?? null) ? $shopifyOrder['line_items'] : [] as $lineItem) {
            if (! is_array($lineItem)) {
                continue;
            }

            $items[] = [
                'lineItemKey' => (string) ($lineItem['id'] ?? ''),
                'name' => $lineItem['title'] ?? '',
                'sku' => $lineItem['sku'] ?? null,
                'quantity' => (int) ($lineItem['quantity'] ?? 1),
                'unitPrice' => (float) ($lineItem['price'] ?? 0),
            ];
        }

        $shippingAmount = 0.0;

        foreach (is_array($shopifyOrder['shipping_lines'] ?? null) ? $shopifyOrder['shipping_lines'] : [] as $shippingLine) {
            $shippingAmount += is_array($shippingLine) ? (float) ($shippingLine['price'] ?? 0) : 0.0;
        }

        return [
            'orderNumber' => (string) ($shopifyOrder['order_number'] ?? $shopifyOrder['name'] ?? ''),
            'orderDate' => $shopifyOrder['created_at'] ?? now()->toIso8601String(),
            'orderStatus' => 'awaiting_shipment',
            'customerEmail' => $shopifyOrder['email'] ?? null,
            'customerUsername' => $shopifyOrder['email'] ?? null,
            'billTo' => $address($shopifyOrder['billing_address'] ?? null),
            'shipTo' => $address($shopifyOrder['shipping_address'] ?? $shopifyOrder['billing_address'] ?? null),
            'items' => $items,
            'amountPaid' => (float) ($shopifyOrder['total_price'] ?? 0),
            'taxAmount' => (float) ($shopifyOrder['total_tax'] ?? 0),
            'shippingAmount' => $shippingAmount,
        ];
    }

    public function createOrder(array $shopifyOrder): array
    {
        return $this->post('/orders/createorder', $this->buildOrderPayload($shopifyOrder));
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        $response = $this->request()
            ->retry(
                self::RETRY_DELAYS_IN_MILLISECONDS,
                when: fn (Throwable $exception, PendingRequest $request, ?string $method): bool => $method === 'GET' && $this->isTransientFailure($exception),
            )
            ->get($path, $query)
            ->throw();

        return $this->decode($response);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        // ponytail: no retry on POST — a retried createorder after a transient
        // failure risks creating the order twice; a failed push should surface
        // to the operator to retry manually, not double-create silently.
        $response = $this->request()->post($path, $body)->throw();

        return $this->decode($response);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->acceptJson()
            ->asJson()
            ->withBasicAuth($this->apiKey, $this->apiSecret)
            ->connectTimeout(3)
            ->timeout(10);
    }

    private function isTransientFailure(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        return $exception->response->status() === 429
            || $exception->response->serverError();
    }

    /**
     * @param  array<string, scalar>  $filters
     * @return list<array<string, mixed>>
     */
    private function paginate(string $path, array $filters, string $itemsKey): array
    {
        $items = [];
        $page = 1;

        do {
            $payload = $this->get($path, [
                ...$filters,
                'pageSize' => self::PAGE_SIZE,
                'page' => $page,
            ]);
            $pageItems = $this->items($payload, $itemsKey);
            array_push($items, ...$pageItems);

            $totalPages = max(1, (int) ($payload['pages'] ?? 1));
            $page++;
        } while ($page <= $totalPages);

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new UnexpectedValueException('ShipStation returned an invalid JSON payload.');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function items(array $payload, string $key): array
    {
        $items = $payload[$key] ?? [];

        if (! is_array($items)) {
            throw new UnexpectedValueException("ShipStation returned an invalid {$key} collection.");
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new UnexpectedValueException("ShipStation returned an invalid {$key} collection.");
            }
        }

        return array_values($items);
    }
}
