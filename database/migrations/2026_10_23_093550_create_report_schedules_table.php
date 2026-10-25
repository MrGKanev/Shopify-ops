<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('tool');
            $table->longText('arguments');
            $table->longText('parameters');
            $table->boolean('uses_date_range')->default(false);
            $table->boolean('enabled')->default(false);
            $table->string('frequency', 10)->default('daily');
            $table->string('time', 5)->default('07:00');
            $table->string('timezone', 64)->default('UTC');
            $table->unsignedTinyInteger('weekday')->default(1);
            $table->unsignedTinyInteger('month_day')->default(1);
            $table->unsignedSmallInteger('lookback_days')->default(30);
            $table->unsignedTinyInteger('end_offset_days')->default(1);
            $table->boolean('notification_enabled')->default(false);
            $table->unsignedInteger('notification_threshold')->default(1);
            $table->string('notification_email', 254)->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
            $table->index(['enabled', 'next_run_at']);
        });
        Schema::table('report_runs', function (Blueprint $table): void {
            $table->foreignId('report_schedule_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('scheduled_for')->nullable();
            $table->json('parameters')->nullable();
            $table->unique(['report_schedule_id', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::table('report_runs', function (Blueprint $table): void {
            $table->dropUnique(['report_schedule_id', 'scheduled_for']);
            $table->dropConstrainedForeignId('report_schedule_id');
            $table->dropColumn(['scheduled_for', 'parameters']);
        });
        Schema::dropIfExists('report_schedules');
    }
};
