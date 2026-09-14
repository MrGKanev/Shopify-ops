<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('tool')->default('unknown');
            $table->string('status')->default('ok');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('duration_seconds', 10, 3)->nullable();
            $table->unsignedInteger('scanned')->nullable();
            $table->unsignedInteger('rows_found')->nullable();
            $table->text('error')->default('');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_logs');
    }
};
