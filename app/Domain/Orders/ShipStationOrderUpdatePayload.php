<?php

namespace App\Domain\Orders;

class ShipStationOrderUpdatePayload
{
    private const array FIELDS = [
        'orderId', 'orderKey', 'orderNumber', 'orderDate', 'paymentDate', 'shipByDate', 'orderStatus',
        'customerId', 'customerUsername', 'customerEmail', 'billTo', 'shipTo', 'items', 'amountPaid',
        'taxAmount', 'shippingAmount', 'customerNotes', 'internalNotes', 'gift', 'giftMessage',
        'paymentMethod', 'requestedShippingService', 'carrierCode', 'serviceCode', 'packageCode',
        'confirmation', 'shipDate', 'weight', 'dimensions', 'insuranceOptions', 'internationalOptions',
        'advancedOptions', 'tagIds',
    ];

    /** @param array<string, mixed> $order
     * @param array<string, mixed> $changes
     * @return array<string, mixed> */
    public static function build(array $order, array $changes = []): array
    {
        return array_replace(array_intersect_key($order, array_flip(self::FIELDS)), $changes);
    }
}
