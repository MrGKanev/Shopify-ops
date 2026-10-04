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
        Schema::create('report_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool');
            $table->string('report');
            $table->json('arguments');
            $table->string('arguments_hash', 64);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('scanned_metric')->default('scanned');
            $table->string('rows_metric')->default('count:rows');
            $table->string('status')->default('queued');
            $table->longText('result')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'tool', 'arguments_hash', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_runs');
    }
};
