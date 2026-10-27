<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operational_issue_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 80);
            $table->string('account', 120)->nullable();
            $table->string('external_reference', 120);
            $table->string('case_key', 255);
            $table->string('order_number', 64)->nullable();
            $table->string('tracking_number', 64)->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('currency', 3);
            $table->decimal('goods_requested', 20, 6)->nullable();
            $table->decimal('goods_approved', 20, 6)->nullable();
            $table->decimal('postage_requested', 20, 6)->nullable();
            $table->decimal('postage_approved', 20, 6)->nullable();
            $table->date('submitted_on')->nullable();
            $table->date('decision_on')->nullable();
            $table->date('follow_up_on')->nullable();
            $table->date('deadline_on')->nullable();
            $table->string('deadline_policy', 255)->nullable();
            $table->timestamp('dates_verified_at')->nullable();
            $table->foreignId('dates_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('evidence')->nullable();
            $table->json('communications')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'case_key']);
            $table->index(['store_id', 'status', 'follow_up_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_claims');
    }
};
