<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\ShippingBillingEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['store_id', 'account_fingerprint', 'source_account', 'transaction_id', 'shipment_id', 'order_id', 'order_number', 'tracking_number', 'type', 'amount', 'currency', 'posted_at', 'carrier', 'service', 'package', 'skus', 'reason', 'statement_reference', 'file_hash', 'payload_hash', 'dispute_status', 'review_note', 'excluded', 'expected_refund', 'refund_due_at'])]
class ShippingBillingEntry extends Model
{
    /** @use HasFactory<ShippingBillingEntryFactory> */
    use BelongsToStore, HasFactory;

    public static function accountFingerprint(Store $store): string
    {
        return hash('sha256', trim((string) $store->shipstation_api_key).'|'.(string) $store->store_number);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:6', 'expected_refund' => 'decimal:6', 'posted_at' => 'immutable_date', 'refund_due_at' => 'immutable_date', 'excluded' => 'boolean', 'review_note' => 'encrypted'];
    }
}
