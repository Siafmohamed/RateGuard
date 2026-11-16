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
        Schema::table('invoices', function (Blueprint $table) {
            // Short failure reason surfaced to the UI when a pipeline stage dies.
            $table->text('error_message')->nullable()->after('status');
            // When the audit last completed successfully.
            $table->timestamp('audited_at')->nullable()->after('error_message');
        });

        // Findings are idempotent per (billed line, rule). Rows with a NULL
        // invoice_line_id are invoice-level findings; both MySQL and SQLite treat
        // NULLs as distinct in a unique index, so those are de-duplicated in code
        // (see App\Domain\Audit\Services\FindingWriter).
        Schema::table('audit_findings', function (Blueprint $table) {
            $table->unique(['invoice_line_id', 'rule']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_findings', function (Blueprint $table) {
            $table->dropUnique(['invoice_line_id', 'rule']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['error_message', 'audited_at']);
        });
    }
};
