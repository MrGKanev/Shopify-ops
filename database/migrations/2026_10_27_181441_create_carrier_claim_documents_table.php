<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_claim_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('carrier_claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name', 120);
            $table->string('path', 255)->unique();
            $table->string('mime_type', 64);
            $table->unsignedInteger('size');
            $table->timestamps();
            $table->index('carrier_claim_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_claim_documents');
    }
};
