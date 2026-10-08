<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ship_station_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 64);
            $table->string('generation', 64);
            $table->string('topic');
            $table->text('payload');
            $table->string('status')->default('received');
            $table->timestamp('available_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('error_category')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'event_key']);
            $table->index(['store_id', 'status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ship_station_events');
    }
};
