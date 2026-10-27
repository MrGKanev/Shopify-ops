<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pick_list_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('columns');
            $table->string('sort', 16)->default('location');
            $table->string('size', 8)->default('normal');
            $table->timestamps();
            $table->unique(['store_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pick_list_templates');
    }
};
