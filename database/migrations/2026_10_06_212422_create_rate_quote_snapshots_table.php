<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_quote_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_number');
            $table->string('shipstation_order_id')->nullable();
            $table->string('status')->default('queued');
            $table->string('mode')->default('simulation');
            $table->text('input');
            $table->text('context')->nullable();
            $table->text('quotes')->nullable();
            $table->string('signature', 64)->nullable();
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('selected_at')->nullable();
            $table->string('selected_service')->nullable();
            $table->string('message')->nullable();
            $table->string('error_category')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'mode', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_quote_snapshots');
    }
};
