<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_claim_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('carrier_claim_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);
            $table->decimal('amount', 20, 6);
            $table->string('currency', 3);
            $table->date('received_on');
            $table->string('source_note', 255);
            $table->string('ledger_reference', 120)->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'ledger_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_claim_payments');
    }
};
