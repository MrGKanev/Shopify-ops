<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('channel');
            $table->string('notification_type');
            $table->string('store_label')->nullable();
            $table->string('recipient')->nullable();
            $table->string('status');
            $table->string('error_category')->nullable();
            $table->timestamps();
            $table->index(['notification_type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
