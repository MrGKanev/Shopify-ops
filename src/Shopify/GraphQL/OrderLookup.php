<?php
declare(strict_types=1);

namespace Shopify\GraphQL;

require_once __DIR__ . '/OrderDirectLookup.php';
require_once __DIR__ . '/OrderHoldLookup.php';
require_once __DIR__ . '/OrderEventLookup.php';

/**
 * Backward-compatible facade for Shopify order lookup operations.
 */
class OrderLookup
{
    private readonly OrderDirectLookup $directLookup;
    private readonly OrderHoldLookup $holdLookup;
    private readonly OrderEventLookup $eventLookup;

    public function __construct(
        Client $client,
        ?\Cache $cache = null
    ) {
        $this->directLookup = new OrderDirectLookup($client);
        $this->holdLookup   = new OrderHoldLookup($client, $cache);
        $this->eventLookup  = new OrderEventLookup($client);
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
