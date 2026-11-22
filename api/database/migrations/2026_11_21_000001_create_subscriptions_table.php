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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // The plan the organization is on. The catalogue of plans lives in
            // configuration (config/rateguard.php -> billing.plans), not in the
            // database, so only the chosen code is persisted here.
            $table->string('plan_code')->nullable();
            // Mirrors the statuses the billing UI branches on: active / canceled.
            $table->string('status')->nullable();
            // Period end the subscription renews on, and the date it ends when
            // it has been set to cancel. Both nullable: the provider drives them.
            $table->date('renews_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->timestamps();

            // One subscription per organization.
            $table->unique('organization_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
