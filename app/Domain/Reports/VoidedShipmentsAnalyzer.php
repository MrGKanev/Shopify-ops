<?php

namespace App\Domain\Reports;

class VoidedShipmentsAnalyzer
{
    /** @param list<array<string, mixed>> $shipments @return list<array<string, mixed>> */
    public function analyze(array $shipments): array
    {
        $rows = array_map(function (array $shipment): array {
            $address = is_array($shipment['shipTo'] ?? null) ? $shipment['shipTo'] : [];

            return [
                'order_number' => $this->text($shipment['orderNumber'] ?? ''), 'shipment_id' => $shipment['shipmentId'] ?? '',
                'tracking' => $this->text($shipment['trackingNumber'] ?? ''), 'carrier' => $this->text($shipment['carrierCode'] ?? ''),
                'service' => $this->text($shipment['serviceCode'] ?? ''), 'ship_date' => substr($this->text($shipment['shipDate'] ?? ''), 0, 10),
                'void_date' => substr($this->text($shipment['voidDate'] ?? ''), 0, 10), 'ship_to_name' => $this->text($address['name'] ?? ''),
                'ship_to_city' => $this->text($address['city'] ?? ''), 'ship_to_state' => $this->text($address['state'] ?? ''),
                'ship_to_zip' => $this->text($address['postalCode'] ?? ''), 'ship_to_country' => $this->text($address['country'] ?? ''),
            ];
        }, $shipments);
        usort($rows, fn (array $a, array $b): int => strcmp($b['void_date'], $a['void_date']));

        return $rows;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
