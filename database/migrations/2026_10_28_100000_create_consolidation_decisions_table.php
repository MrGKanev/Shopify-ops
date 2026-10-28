<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consolidation_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('order_number', 64);
            $table->string('decision', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['store_id', 'order_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consolidation_decisions');
    }
};
