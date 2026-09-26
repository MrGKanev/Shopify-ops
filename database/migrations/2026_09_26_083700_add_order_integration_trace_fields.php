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
        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->index(['store_id', 'subject_id']);
        });
        Schema::table('push_logs', function (Blueprint $table): void {
            $table->string('status')->default('success');
            $table->string('error_category')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->dropIndex(['store_id', 'subject_id']);
        });
        Schema::table('push_logs', function (Blueprint $table): void {
            $table->dropColumn(['status', 'error_category']);
        });
    }
};
