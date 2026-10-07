<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remediation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('group_uuid')->index();
            $table->string('batch_id')->nullable();
            $table->string('order_number');
            $table->string('shopify_id')->nullable();
            $table->string('action');
            $table->string('status')->default('draft');
            $table->text('plan')->nullable();
            $table->unsignedInteger('completed_steps')->default(0);
            $table->string('result_message')->nullable();
            $table->string('error_category')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'shopify_id', 'action', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remediation_runs');
    }
};
