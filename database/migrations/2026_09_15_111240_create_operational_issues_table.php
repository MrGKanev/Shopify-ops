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
        Schema::create('operational_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_tool');
            $table->string('fingerprint');
            $table->string('title');
            $table->string('reference')->nullable();
            $table->string('status')->default('open');
            $table->string('priority')->default('normal');
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->date('due_date')->nullable();
            $table->text('resolution_note')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'fingerprint']);
            $table->index(['store_id', 'status', 'priority', 'last_seen_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operational_issues');
    }
};
