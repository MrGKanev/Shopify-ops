<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_schedules', function (Blueprint $table): void {
            $table->string('last_run_status')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->text('last_run_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('report_schedules', function (Blueprint $table): void {
            $table->dropColumn(['last_run_status', 'last_finished_at', 'last_run_error']);
        });
    }
};
