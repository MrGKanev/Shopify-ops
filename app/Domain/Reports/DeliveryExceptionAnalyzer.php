<?php

namespace App\Domain\Reports;

class DeliveryExceptionAnalyzer
{
    /** @param list<array<string, mixed>> $shipments @return list<array{fingerprint:string,reference:string,title:string,priority:string,days:int,resolved:bool,payload:array<string,mixed>}> */
    public function analyze(array $shipments, int $threshold, int $now): array
    {
        $rows = [];

        foreach ($shipments as $shipment) {
            $shipDate = is_scalar($shipment['shipDate'] ?? null) ? trim((string) $shipment['shipDate']) : '';
            $shippedAt = $shipDate !== '' ? strtotime($shipDate) : false;
            if ($shippedAt === false || $shippedAt > $now) {
                continue;
            }

            $days = (int) floor(($now - $shippedAt) / 86400);
            $orderNumber = trim(is_scalar($shipment['orderNumber'] ?? null) ? (string) $shipment['orderNumber'] : '');
            $tracking = trim(is_scalar($shipment['trackingNumber'] ?? null) ? (string) $shipment['trackingNumber'] : '');
            $shipmentId = trim(is_scalar($shipment['shipmentId'] ?? null) ? (string) $shipment['shipmentId'] : '');
            if ($orderNumber === '' && $tracking === '' && $shipmentId === '') {
                continue;
            }

            $identity = $shipmentId ?: ($tracking ?: $orderNumber.'|'.$shipDate);

            $resolved = ! empty($shipment['deliveryDate']) || ! empty($shipment['voidDate']);
            if (! $resolved && $days < $threshold) {
                continue;
            }

            $reference = $orderNumber !== '' ? $orderNumber : ($tracking !== '' ? $tracking : 'Shipment '.$shipmentId);
            $rows[] = [
                'fingerprint' => hash('sha256', 'delivery_watch|'.$identity),
                'reference' => $reference,
                'title' => 'No delivery confirmation for '.($orderNumber !== '' ? 'order '.$orderNumber : ($tracking !== '' ? 'shipment '.$tracking : 'shipment '.$shipmentId)),
                'priority' => $days >= 14 ? 'urgent' : ($days >= 8 ? 'high' : 'normal'),
                'days' => $days,
                'resolved' => $resolved,
                'payload' => [
                    'shipment_id' => $shipmentId,
                    'tracking_number' => $tracking,
                    'order_number' => $orderNumber,
                    'carrier' => is_scalar($shipment['carrierCode'] ?? null) ? (string) $shipment['carrierCode'] : '',
                    'ship_date' => $shipDate,
                    'days_without_delivery_confirmation' => $days,
                ],
            ];
        }

        return $rows;
    }
}
