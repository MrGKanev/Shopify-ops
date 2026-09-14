<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('tool');
            $table->date('report_date');
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('rows_found');
            $table->json('result');
            $table->timestamps();
            $table->unique(['store_id', 'tool', 'report_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_snapshots');
    }
};
