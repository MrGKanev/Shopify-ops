<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->json('movement_policy')->nullable();
        });
        Schema::create('tracking_read_budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('budget_date');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
            $table->unique(['store_id', 'budget_date']);
        });
        Schema::create('shipment_movement_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('account_fingerprint', 64);
            $table->string('identity', 64);
            $table->string('shipment_id');
            $table->string('order_number');
            $table->string('carrier');
            $table->string('tracking_number');
            $table->string('classification');
            $table->dateTime('carrier_acceptance_at')->nullable();
            $table->dateTime('last_progress_at')->nullable();
            $table->dateTime('checked_at');
            $table->timestamps();
            $table->unique(['store_id', 'identity']);
            $table->index(['store_id', 'account_fingerprint', 'shipment_id'], 'movement_account_shipment');
            $table->index(['store_id', 'account_fingerprint', 'carrier_acceptance_at'], 'movement_account_acceptance');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_movement_observations');
        Schema::dropIfExists('tracking_read_budgets');
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('movement_policy'));
    }
};
