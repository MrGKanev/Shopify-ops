<?php

namespace App\Application\Orders;

use App\Domain\Orders\CustomerFulfillmentContext;
use App\Integrations\ShipStation\ShipStationClientFactory;
use App\Integrations\Shopify\Contracts\ShopifyCustomers;
use App\Models\Store;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

class LoadCustomerContext
{
    public const MAX_CUSTOMERS = 25;

    public function __construct(private readonly ShipStationClientFactory $clients, private readonly ShopifyCustomers $shopify, private readonly CustomerFulfillmentContext $context, private readonly FindStoreShipStationOrders $findOrders) {}

    /**
     * Read-only: orders that cannot be found, match twice or exceed the customer cap are listed rather than guessed.
     *
     * @param  list<string>  $orderNumbers
     * @param  list<int>|null  $milestones
     * @return array{rows: list<array{order: string, email: string, skus: list<string>, status: string, priorOrders: int, atLeast: bool, orderNumber: int|null, tier: string|null, repeatedSkus: list<string>, skuHistoryComplete: bool, reasons: list<string>}>, missing: list<string>, ambiguous: list<string>, skipped: list<string>}
     */
    public function handle(Store $store, array $orderNumbers, int $vipAt = 5, ?array $milestones = null): array
    {
        $client = $this->clients->forStore($store);
        if ($client === null) {
            throw new LogicException('ShipStation is not configured for this store.');
        }
        $missing = $ambiguous = $skipped = $orders = [];
        foreach ($orderNumbers as $number) {
            $matches = $this->findOrders->handle($store, $client, $number);
            match (count($matches)) {
                0 => $missing[] = $number,
                1 => $orders[] = ['number' => $number, 'order' => $matches[0]],
                default => $ambiguous[] = $number,
            };
        }

        $histories = [];
        $rows = [];
        foreach ($orders as ['number' => $number, 'order' => $order]) {
            $email = mb_strtolower(trim((string) ($order['customerEmail'] ?? '')));
            $skus = array_values(array_filter(array_map(fn (mixed $item): string => is_array($item) && is_string($item['sku'] ?? null) ? $item['sku'] : '', is_array($order['items'] ?? null) ? $order['items'] : [])));
            if ($email !== '' && ! array_key_exists($email, $histories)) {
                if (count($histories) >= self::MAX_CUSTOMERS) {
                    $skipped[] = $number;

                    continue;
                }
                $histories[$email] = $this->history($store, $email);
            }
            $history = $histories[$email] ?? null;
            $context = $this->context->classify($email, $number, $history['orders'] ?? [], $history['truncated'] ?? false, $skus, $vipAt, $milestones);
            if ($email !== '' && $history === null) {
                $context['reasons'] = ['lookup_failed'];
            }
            $rows[] = ['order' => $number, 'email' => $email, 'skus' => $skus] + $context;
        }

        return ['rows' => $rows, 'missing' => $missing, 'ambiguous' => $ambiguous, 'skipped' => $skipped];
    }

    /** @return array{orders: list<array{name: string, created_at: string, cancelled: bool, test: bool, customer_id: string, skus: list<string>, lines_complete: bool}>, truncated: bool}|null */
    private function history(Store $store, string $email): ?array
    {
        try {
            $result = $this->shopify->customerFulfillmentHistory($store, $email);

            return ['orders' => $result['orders'], 'truncated' => $result['truncated']];
        } catch (Throwable $exception) {
            Log::warning('Customer context history lookup failed.', ['exception_type' => $exception::class]);

            return null;
        }
    }
}
