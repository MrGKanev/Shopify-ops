<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\ShipmentMovementObservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['store_id', 'account_fingerprint', 'identity', 'shipment_id', 'order_number', 'carrier', 'tracking_number', 'classification', 'carrier_acceptance_at', 'last_progress_at', 'checked_at'])]
class ShipmentMovementObservation extends Model
{
    /** @use HasFactory<ShipmentMovementObservationFactory> */
    use BelongsToStore, HasFactory;

    public static function accountFingerprint(Store $store): string
    {
        return hash('sha256', $store->shipstation_api_key.'|'.$store->store_number);
    }

    /** @param array<string, mixed> $shipment */
    public static function shipmentIdentity(Store $store, array $shipment): string
    {
        return hash('sha256', $store->shipstation_api_key.'|'.$store->store_number.'|'.$shipment['shipmentId'].'|'.$shipment['carrierCode'].'|'.$shipment['trackingNumber']);
    }

    protected function casts(): array
    {
        return ['carrier_acceptance_at' => 'immutable_datetime', 'last_progress_at' => 'immutable_datetime', 'checked_at' => 'immutable_datetime'];
    }
}
