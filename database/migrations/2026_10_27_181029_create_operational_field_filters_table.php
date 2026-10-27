<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_field_filters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operational_field_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('condition', 16);
            $table->string('text', 120)->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_field_filters');
    }
};
