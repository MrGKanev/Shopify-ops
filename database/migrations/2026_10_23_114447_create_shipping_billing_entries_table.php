<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_billing_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('account_fingerprint', 64);
            $table->string('source_account', 80);
            $table->string('transaction_id', 100);
            $table->string('shipment_id', 24);
            $table->string('order_id', 24);
            $table->string('order_number', 100);
            $table->string('tracking_number', 100)->nullable();
            $table->string('type', 30);
            $table->decimal('amount', 20, 6);
            $table->string('currency', 3);
            $table->date('posted_at');
            $table->string('carrier', 80)->nullable();
            $table->string('service', 80)->nullable();
            $table->string('package', 80)->nullable();
            $table->string('skus', 500)->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('statement_reference', 160);
            $table->string('file_hash', 64);
            $table->string('payload_hash', 64);
            $table->string('dispute_status', 20)->default('none');
            $table->text('review_note')->nullable();
            $table->boolean('excluded')->default(false);
            $table->decimal('expected_refund', 20, 6)->nullable();
            $table->date('refund_due_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'account_fingerprint', 'source_account', 'transaction_id'], 'shipping_billing_transaction_unique');
            $table->index(['store_id', 'account_fingerprint', 'shipment_id', 'posted_at'], 'shipping_billing_shipment_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_billing_entries');
    }
};
