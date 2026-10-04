<?php

namespace App;

/**
 * An external system whose store credentials a tool needs before it can run.
 */
enum Integration: string
{
    case Shopify = 'shopify';
    case ShipStation = 'shipstation';

    public function label(): string
    {
        return match ($this) {
            self::Shopify => 'Shopify',
            self::ShipStation => 'ShipStation',
        };
    }
}
