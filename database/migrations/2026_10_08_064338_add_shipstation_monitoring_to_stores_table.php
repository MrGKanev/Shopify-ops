<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->boolean('shipstation_monitoring_enabled')->default(false);
            $table->text('shipstation_monitoring_token')->nullable();
            $table->text('shipstation_monitoring_subscriptions')->nullable();
            $table->timestamp('shipstation_monitoring_started_at')->nullable();
            $table->timestamp('shipstation_monitoring_checked_at')->nullable();
            $table->string('shipstation_monitoring_status')->default('disabled');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn(['shipstation_monitoring_enabled', 'shipstation_monitoring_token', 'shipstation_monitoring_subscriptions', 'shipstation_monitoring_started_at', 'shipstation_monitoring_checked_at', 'shipstation_monitoring_status']);
        });
    }
};
