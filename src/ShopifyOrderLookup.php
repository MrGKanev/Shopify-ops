<?php
declare(strict_types=1);

require_once __DIR__ . '/ShopifyOrderDirectLookup.php';
require_once __DIR__ . '/ShopifyOrderHoldLookup.php';
require_once __DIR__ . '/ShopifyOrderEventLookup.php';

/**
 * Backward-compatible facade for Shopify order lookup operations.
 */
class ShopifyOrderLookup
{
    private readonly ShopifyOrderDirectLookup $directLookup;
    private readonly ShopifyOrderHoldLookup $holdLookup;
    private readonly ShopifyOrderEventLookup $eventLookup;

    public function __construct(
        ShopifyGraphQLClient $client,
        ?Cache $cache = null
    ) {
        $this->directLookup = new ShopifyOrderDirectLookup($client);
        $this->holdLookup   = new ShopifyOrderHoldLookup($client, $cache);
        $this->eventLookup  = new ShopifyOrderEventLookup($client);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByOrderNumber(string $orderNumber): array
    {
        return $this->directLookup->findByOrderNumber($orderNumber);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId): array
    {
        return $this->directLookup->getOrder($orderId);
    }

    public function isOnHold(string $orderId): bool
    {
        return $this->holdLookup->isOnHold($orderId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOrderEvents(string $orderId): array
    {
        return $this->eventLookup->getOrderEvents($orderId);
    }
}
