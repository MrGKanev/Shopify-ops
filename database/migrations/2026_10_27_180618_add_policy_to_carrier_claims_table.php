<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_claims', function (Blueprint $table): void {
            $table->foreignId('carrier_claim_policy_id')->nullable()->after('deadline_policy')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('carrier_claims', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('carrier_claim_policy_id');
        });
    }
};
