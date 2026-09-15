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
        Schema::create('backup_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('path');
            $table->string('status');
            $table->unsignedBigInteger('archive_size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedInteger('entries')->nullable();
            $table->string('database_dump')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('verified_at');
            $table->timestamps();

            $table->index(['path', 'verified_at']);
            $table->index(['status', 'verified_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_verifications');
    }
};
