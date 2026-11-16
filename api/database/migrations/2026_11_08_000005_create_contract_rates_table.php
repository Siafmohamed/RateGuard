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
        // Rate-card line items. A row either prices a weight band
        // (charge_type = null) or declares an allowed accessorial/surcharge
        // (charge_type = 'fuel' | 'residential' | 'demand' ...).
        Schema::create('contract_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('service_level');
            $table->string('zone')->nullable();
            // Weight band, documented in kilograms.
            $table->decimal('min_weight_kg', 10, 2);
            $table->decimal('max_weight_kg', 10, 2);
            // Money is always stored as integer cents.
            $table->unsignedBigInteger('rate_cents');
            $table->string('charge_type')->nullable();
            $table->timestamps();

            $table->index('organization_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_rates');
    }
};
