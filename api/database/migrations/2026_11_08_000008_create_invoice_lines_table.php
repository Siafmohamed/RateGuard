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
        // One billed charge on an invoice, including accessorials.
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tracking_number')->index();
            // base | fuel | residential | demand | dim_weight_adjustment | other
            $table->string('charge_type');
            $table->string('description')->nullable();
            // Weight the carrier says it billed, in kilograms.
            $table->decimal('billed_weight_kg', 10, 2)->nullable();
            // Money is always stored as integer cents.
            $table->unsignedBigInteger('amount_cents');
            $table->timestamps();

            $table->index('organization_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
