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
        Schema::table('push_logs', function (Blueprint $table): void {
            $table->index(['store_id', 'pushed_at']);
            $table->index(['store_id', 'shopify_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('push_logs', function (Blueprint $table): void {
            $table->dropIndex(['store_id', 'pushed_at']);
            $table->dropIndex(['store_id', 'shopify_id', 'status']);
        });
    }
};
