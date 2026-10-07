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
            $table->string('payload_hash', 64)->nullable();
            $table->text('payload_snapshot')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('push_logs', function (Blueprint $table): void {
            $table->dropColumn(['payload_hash', 'payload_snapshot']);
        });
    }
};
