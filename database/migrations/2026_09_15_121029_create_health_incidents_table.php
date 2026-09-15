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
        Schema::create('health_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('check_name');
            $table->string('check_label');
            $table->string('severity');
            $table->text('summary')->nullable();
            $table->unsignedInteger('observations')->default(1);
            $table->timestamp('started_at');
            $table->timestamp('last_observed_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['resolved_at', 'started_at']);
            $table->index(['check_name', 'resolved_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('health_incidents');
    }
};
