<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_queue_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('order_number');
            $table->string('note')->default('');
            $table->timestamps();
            $table->unique(['store_id', 'order_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_queue_items');
    }
};
