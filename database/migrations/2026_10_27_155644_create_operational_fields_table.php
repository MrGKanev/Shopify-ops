<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('alias', 80);
            $table->string('source_type', 16);
            $table->string('source', 255);
            $table->boolean('required')->default(false);
            $table->timestamps();
            $table->unique(['store_id', 'alias']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_fields');
    }
};
