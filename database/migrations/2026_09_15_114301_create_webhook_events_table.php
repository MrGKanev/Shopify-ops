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
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('webhook_id');
            $table->string('topic');
            $table->string('shop_domain');
            $table->string('api_version')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('status')->default('received');
            $table->text('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('error_category')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'webhook_id']);
            $table->index(['store_id', 'topic', 'occurred_at']);
            $table->index(['store_id', 'status', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
