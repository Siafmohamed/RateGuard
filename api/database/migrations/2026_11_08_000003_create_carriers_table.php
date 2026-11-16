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
        // Global reference table: shared by every organization, so there is
        // deliberately no organization_id and no tenancy scope on this table.
        Schema::create('carriers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // UPS / FEDEX / DHL
            $table->string('name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carriers');
    }
};
