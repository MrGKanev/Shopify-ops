<?php

namespace App\Application\Orders;

use RuntimeException;

/**
 * Thrown when a push to ShipStation is stopped because the shipping address has critical problems
 * and the operator has not confirmed pushing anyway.
 */
class ShippingAddressNeedsReview extends RuntimeException
{
    /**
     * @param  list<array{level: 'critical'|'warning', code: string, message: string}>  $issues
     */
    public function __construct(public readonly string $orderNumber, public readonly array $issues)
    {
        parent::__construct("Order {$orderNumber} has shipping address problems. Review them, then confirm to push anyway.");
    }
}
