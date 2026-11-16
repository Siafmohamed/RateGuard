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
        // There is at most one claim per audit finding. DraftClaimsJob uses
        // firstOrCreate, which is only race-safe on a re-run when the database
        // itself enforces the key.
        Schema::table('claims', function (Blueprint $table) {
            $table->unique('audit_finding_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropUnique(['audit_finding_id']);
        });
    }
};
