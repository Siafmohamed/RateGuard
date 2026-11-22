<?php

namespace Tests\Feature;

use App\Domain\Audit\Data\FindingData;
use App\Domain\Audit\Models\AuditFinding;
use App\Domain\Audit\Services\FindingWriter;
use App\Domain\Claims\Models\Claim;
use App\Domain\Contracts\Models\Contract;
use App\Domain\Contracts\Models\ContractRate;
use App\Domain\Invoices\Jobs\AuditInvoiceJob;
use App\Domain\Invoices\Jobs\InvoicePipeline;
use App\Domain\Invoices\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Carrier;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the invoice pipeline end to end: upload -> parse -> normalize -> audit
 * -> draft claims, plus the read/re-audit endpoints.
 *
 * The queue runs inline (QUEUE_CONNECTION=sync), so by the time an upload
 * returns the whole pipeline has already executed.
 */
class InvoicePipelineTest extends TestCase
{
    use RefreshDatabase;

    private function carrier(string $code = 'UPS'): Carrier
    {
        return Carrier::create(['code' => $code, 'name' => $code.' Carrier']);
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function organizationWithUser(Role $role, string $name = 'Acme Shipping'): array
    {
        $user = User::factory()->create();

        $organization = Organization::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
        ]);

        $user->organizations()->attach($organization->id, ['role' => $role->value]);

        return [$user, $organization];
    }

    /**
     * Two identical fuel lines on one tracking number: the second is a
     * duplicate charge (186 cents) that the engine must recover.
     */
    private function duplicateCsv(): string
    {
        return implode("\n", [
            'tracking_number,charge_type,description,billed_weight_kg,amount',
            '1ZTEST0001,fuel,Fuel surcharge,,1.86',
            '1ZTEST0001,fuel,Fuel surcharge,,1.86',
        ]);
    }

    /**
     * A carrier label ("Ground Transportation") that must be normalised onto the
     * canonical "base" charge type; the second line is then a duplicate.
     */
    private function aliasCsv(): string
    {
        return implode("\n", [
            'tracking_number,charge_type,description,billed_weight_kg,amount',
            '1ZTEST0002,Ground Transportation,Ground transportation,3.00,15.00',
            '1ZTEST0002,Ground Transportation,Ground transportation,3.00,15.00',
        ]);
    }

    /**
     * Row 2 carries a non-numeric amount, which must fail the whole import.
     */
    private function malformedCsv(): string
    {
        return implode("\n", [
            'tracking_number,charge_type,description,billed_weight_kg,amount',
            '1ZTEST0003,fuel,Fuel surcharge,,not-a-number',
        ]);
    }

    private function upload(User $user, string $csv, string $number = 'ACME-UPS-0001', string $carrier = 'UPS')
    {
        // A real SPA replays the CSRF token first; this test targets the
        // endpoint's behaviour, not CSRF protection.
        $this->withoutMiddleware(ValidateCsrfToken::class);

        return $this->actingAs($user, 'sanctum')->post('/api/v1/invoices/upload', [
            'file' => UploadedFile::fake()->createWithContent('invoice.csv', $csv),
            'carrier' => $carrier,
            'invoice_number' => $number,
            'invoice_date' => '2026-02-28',
        ], ['Accept' => 'application/json']);
    }

    private function invoiceByNumber(string $number): Invoice
    {
        return Invoice::withoutTenancy()->where('invoice_number', $number)->firstOrFail();
    }

    public function test_upload_runs_the_pipeline_stores_the_file_and_records_findings(): void
    {
        Storage::fake('local');
        $this->carrier('UPS');
        [$user, $organization] = $this->organizationWithUser(Role::Admin);

        $response = $this->upload($user, $this->duplicateCsv());

        $response->assertStatus(202);

        $invoice = $this->invoiceByNumber('ACME-UPS-0001');

        // The file is on the configured disk, under a per-tenant folder.
        $this->assertStringStartsWith('invoices/'.$organization->id.'/', $invoice->source_file);
        Storage::disk('local')->assertExists($invoice->source_file);

        // The whole pipeline completed.
        $this->assertSame(InvoiceStatus::Audited->value, $invoice->status);
        $this->assertNotNull($invoice->audited_at);
        $this->assertSame(372, $invoice->total_cents);

        $findings = AuditFinding::withoutTenancy()->where('invoice_id', $invoice->id)->get();

        $this->assertCount(1, $findings);
        $this->assertSame('duplicate_charge', $findings->first()->rule);
        $this->assertSame(186, $findings->first()->recoverable_cents);

        // Stage 4 drafted a claim for the recoverable finding.
        $this->assertTrue(Claim::withoutTenancy()
            ->where('audit_finding_id', $findings->first()->getKey())
            ->exists());
    }

    public function test_charge_type_aliases_are_normalised_before_the_audit(): void
    {
        Storage::fake('local');
        $this->carrier('UPS');
        [$user] = $this->organizationWithUser(Role::Admin);

        $this->upload($user, $this->aliasCsv(), 'ACME-UPS-ALIAS-0001')->assertStatus(202);

        $invoice = $this->invoiceByNumber('ACME-UPS-ALIAS-0001');

        $this->assertSame(
            ['base', 'base'],
            $invoice->lines()->orderBy('id')->pluck('charge_type')->all(),
        );

        // Normalising "Ground Transportation" to "base" exposes the duplicate.
        $this->assertSame(1, AuditFinding::withoutTenancy()->where('invoice_id', $invoice->id)->count());
    }

    public function test_a_member_cannot_upload_an_invoice(): void
    {
        Storage::fake('local');
        $this->carrier('UPS');
        [$user] = $this->organizationWithUser(Role::Member);

        $this->upload($user, $this->duplicateCsv())->assertForbidden();

        $this->assertSame(0, Invoice::withoutTenancy()->count());
    }

    public function test_show_returns_status_findings_count_and_recoverable_total(): void
    {
        Storage::fake('local');
        $this->carrier('UPS');
        [$user] = $this->organizationWithUser(Role::Admin);

        $this->upload($user, $this->duplicateCsv())->assertStatus(202);
        $invoice = $this->invoiceByNumber('ACME-UPS-0001');

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/invoices/'.$invoice->id);

        $response->assertOk();
        $response->assertJsonPath('data.status', InvoiceStatus::Audited->value);
        $response->assertJsonPath('data.findings_count', 1);
        $response->assertJsonPath('data.recoverable_total_cents', 186);
        $response->assertJsonStructure(['data' => ['error_message', 'audited_at']]);
    }

    public function test_an_audited_invoice_without_a_contract_explains_that_no_rules_ran(): void
    {
        Storage::fake('local');
        $this->carrier('UPS');
        [$user] = $this->organizationWithUser(Role::Admin);

        // A valid invoice with no anomalies, uploaded without a contract for the
        // carrier: the audit can therefore raise no overcharge finding.
        $csv = implode("\n", [
            'tracking_number,charge_type,description,billed_weight_kg,amount',
            '1ZTEST0009,shipping,Standard delivery,1.5,85.00',
        ]);

        $this->upload($user, $csv, 'ACME-NOCONTRACT-0001')->assertStatus(202);

        $invoice = $this->invoiceByNumber('ACME-NOCONTRACT-0001');

        $this->assertSame(InvoiceStatus::Audited->value, $invoice->status);
        $this->assertNull($invoice->contract_id);
        $this->assertSame(0, AuditFinding::withoutTenancy()->where('invoice_id', $invoice->id)->count());

        // Rather than silently reporting "0 findings", the audit says why.
        $this->assertNotNull($invoice->error_message);
        $this->assertStringContainsString('No contract is linked', $invoice->error_message);
    }

    public function test_the_upload_links_the_contract_for_the_carrier_so_the_audit_can_compare(): void
    {
        Storage::fake('local');
        $this->carrier('UPS');
        [$user, $organization] = $this->organizationWithUser(Role::Admin);

        // A contract for this organization + carrier that only allows fuel.
        app(CurrentOrganization::class)->set($organization);

        $contract = Contract::create([
            'carrier_id' => Carrier::firstOrFail()->id,
            'name' => 'Acme / UPS 2026',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'dim_divisor' => 5000,
        ]);

        ContractRate::create([
            'contract_id' => $contract->id,
            'service_level' => 'GROUND',
            'charge_type' => 'fuel',
            'zone' => null,
            'min_weight_kg' => 0,
            'max_weight_kg' => 0,
            'rate_cents' => 1200,
        ]);

        app(CurrentOrganization::class)->forget();

        $csv = implode("\n", [
            'tracking_number,charge_type,description,billed_weight_kg,amount',
            '1ZTESTLINK1,demand,Demand surcharge,,12.50',
        ]);

        $this->upload($user, $csv, 'ACME-LINK-0001')->assertStatus(202);

        $invoice = $this->invoiceByNumber('ACME-LINK-0001');

        // The upload linked the contract...
        $this->assertSame($contract->id, $invoice->contract_id);

        // ...so the audit compared the invoice against it and flagged the
        // "demand" surcharge the contract does not allow.
        $finding = AuditFinding::withoutTenancy()
            ->where('invoice_id', $invoice->id)
            ->where('rule', 'unauthorized_surcharge')
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame(1250, $finding->recoverable_cents);
    }

    public function test_findings_endpoint_lists_rule_severity_and_tracking_number(): void
    {
        Storage::fake('local');
        $this->carrier('UPS');
        [$user] = $this->organizationWithUser(Role::Admin);

        $this->upload($user, $this->duplicateCsv())->assertStatus(202);
        $invoice = $this->invoiceByNumber('ACME-UPS-0001');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/invoices/'.$invoice->id.'/findings');

        $response->assertOk();
        $response->assertJsonPath('data.0.rule', 'duplicate_charge');
        $response->assertJsonPath('data.0.severity', 'low');
        $response->assertJsonPath('data.0.billed_cents', 186);
        $response->assertJsonPath('data.0.recoverable_cents', 186);
        $response->assertJsonPath('data.0.status', 'open');
        $response->assertJsonPath('data.0.tracking_number', '1ZTEST0001');
    }

    public function test_re_running_the_audit_is_idempotent(): void
    {
        Storage::fake('local');
        $this->carrier('UPS');
        [$user] = $this->organizationWithUser(Role::Admin);

        $this->upload($user, $this->duplicateCsv())->assertStatus(202);
        $invoice = $this->invoiceByNumber('ACME-UPS-0001');

        $this->withoutMiddleware(ValidateCsrfToken::class);

        // Run the audit twice more.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/invoices/'.$invoice->id.'/audit')
            ->assertStatus(202);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/invoices/'.$invoice->id.'/audit')
            ->assertStatus(202);

        $findings = AuditFinding::withoutTenancy()->where('invoice_id', $invoice->id)->get();

        $this->assertCount(1, $findings);
        $this->assertSame(186, (int) $findings->sum('recoverable_cents'));
        $this->assertSame(1, Claim::withoutTenancy()
            ->where('audit_finding_id', $findings->first()->getKey())
            ->count());
    }

    public function test_re_audit_is_rejected_while_the_invoice_is_being_processed(): void
    {
        Storage::fake('local');
        $this->carrier('UPS');
        [$user] = $this->organizationWithUser(Role::Admin);

        $this->upload($user, $this->duplicateCsv())->assertStatus(202);
        $invoice = $this->invoiceByNumber('ACME-UPS-0001');

        Invoice::withoutTenancy()->whereKey($invoice->id)->update(['status' => InvoiceStatus::Auditing->value]);

        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/invoices/'.$invoice->id.'/audit')
            ->assertStatus(409);
    }

    public function test_a_finding_a_human_already_acted_on_is_not_overwritten(): void
    {
        Storage::fake('local');
        $this->carrier('UPS');
        [$user] = $this->organizationWithUser(Role::Admin);

        $this->upload($user, $this->duplicateCsv())->assertStatus(202);
        $invoice = $this->invoiceByNumber('ACME-UPS-0001');

        $finding = AuditFinding::withoutTenancy()->where('invoice_id', $invoice->id)->firstOrFail();

        // A human disputed the finding and re-priced it; the draft claim the
        // first run produced was discarded when they acted on it.
        AuditFinding::withoutTenancy()->whereKey($finding->id)->update([
            'status' => 'disputed',
            'recoverable_cents' => 999,
        ]);
        Claim::withoutTenancy()->where('audit_finding_id', $finding->id)->delete();

        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/invoices/'.$invoice->id.'/audit')
            ->assertStatus(202);

        $fresh = AuditFinding::withoutTenancy()->findOrFail($finding->id);

        $this->assertSame('disputed', $fresh->status);
        $this->assertSame(999, $fresh->recoverable_cents);

        // No claim is drafted for a finding that is no longer open.
        $this->assertFalse(Claim::withoutTenancy()->where('audit_finding_id', $finding->id)->exists());
    }

    public function test_a_malformed_csv_row_fails_the_invoice_and_names_the_row(): void
    {
        Storage::fake('local');
        $this->carrier('UPS');
        [$user] = $this->organizationWithUser(Role::Admin);

        // The sync queue re-throws, so the request itself errors.
        $this->upload($user, $this->malformedCsv(), 'ACME-BAD-0001')->assertStatus(500);

        $invoice = $this->invoiceByNumber('ACME-BAD-0001');

        $this->assertSame(InvoiceStatus::Failed->value, $invoice->status);
        $this->assertStringContainsString('row 2', $invoice->error_message);
        $this->assertStringContainsString('amount', $invoice->error_message);

        // Nothing was imported.
        $this->assertSame(0, $invoice->lines()->count());
    }

    public function test_a_non_invoice_csv_header_is_rejected_before_creating_an_invoice(): void
    {
        Storage::fake('local');
        $this->carrier('UPS');
        [$user] = $this->organizationWithUser(Role::Admin);

        $this->upload(
            $user,
            "index,ean,stock,price\n1,2010743556564,669,135\n",
            'ACME-INVENTORY-0001',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file')
            ->assertJsonPath(
                'errors.file.0',
                'The invoice CSV must have this header row: tracking_number,charge_type,description,billed_weight_kg,amount.',
            );

        $this->assertSame(0, Invoice::withoutTenancy()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_job_cannot_read_an_invoice_of_another_organization(): void
    {
        $this->carrier('UPS');
        [$acmeUser, $acme] = $this->organizationWithUser(Role::Owner, 'Acme Shipping');
        [, $globex] = $this->organizationWithUser(Role::Owner, 'Globex Freight');

        // An invoice that belongs to Globex, created with its tenant in context.
        app(\App\Support\Tenancy\CurrentOrganization::class)->set($globex);
        $invoice = Invoice::create([
            'carrier_id' => Carrier::firstOrFail()->id,
            'invoice_number' => 'GLOBEX-0001',
            'invoice_date' => '2026-03-31',
            'currency' => 'USD',
            'total_cents' => 0,
            'status' => 'imported',
        ]);
        app(\App\Support\Tenancy\CurrentOrganization::class)->forget();

        // Acme's job pointed at Globex's invoice id sees nothing.
        $this->expectException(ModelNotFoundException::class);

        Bus::dispatchSync(new AuditInvoiceJob($acme->id, $invoice->id));
    }

    public function test_a_user_cannot_read_findings_of_another_organizations_invoice(): void
    {
        Storage::fake('local');
        $this->carrier('UPS');
        [$acmeUser] = $this->organizationWithUser(Role::Owner, 'Acme Shipping');

        $this->upload($acmeUser, $this->duplicateCsv())->assertStatus(202);
        $invoice = $this->invoiceByNumber('ACME-UPS-0001');

        [$globexUser] = $this->organizationWithUser(Role::Owner, 'Globex Freight');

        $this->actingAs($globexUser, 'sanctum')
            ->getJson('/api/v1/invoices/'.$invoice->id.'/findings')
            ->assertNotFound();
    }

    public function test_the_invoice_disk_can_be_changed_by_configuration_alone(): void
    {
        // Point the abstraction at a different disk; no application code changes.
        Storage::fake('public');
        config(['rateguard.invoice_disk' => 'public']);

        $this->carrier('UPS');
        [$user] = $this->organizationWithUser(Role::Admin);

        $this->upload($user, $this->duplicateCsv(), 'ACME-UPS-ALT-0001')->assertStatus(202);

        $invoice = $this->invoiceByNumber('ACME-UPS-ALT-0001');

        Storage::disk('public')->assertExists($invoice->source_file);
    }

    public function test_a_non_csv_file_is_rejected(): void
    {
        Storage::fake('local');
        $this->carrier('UPS');
        [$user] = $this->organizationWithUser(Role::Admin);

        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->actingAs($user, 'sanctum')->post('/api/v1/invoices/upload', [
            'file' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'),
            'carrier' => 'UPS',
            'invoice_number' => 'ACME-PDF-0001',
            'invoice_date' => '2026-02-28',
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, Invoice::withoutTenancy()->count());
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        Storage::fake('local');
        $this->carrier('UPS');
        [$user] = $this->organizationWithUser(Role::Admin);

        $this->withoutMiddleware(ValidateCsrfToken::class);

        // 5121 KB is just over the 5120 KB (5 MB) cap.
        $this->actingAs($user, 'sanctum')->post('/api/v1/invoices/upload', [
            'file' => UploadedFile::fake()->create('invoice.csv', 5121, 'text/csv'),
            'carrier' => 'UPS',
            'invoice_number' => 'ACME-BIG-0001',
            'invoice_date' => '2026-02-28',
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, Invoice::withoutTenancy()->count());
    }

    public function test_invoice_level_findings_are_replaced_on_re_run_but_acted_on_ones_are_kept(): void
    {
        Storage::fake('local');
        $this->carrier('UPS');
        [$user] = $this->organizationWithUser(Role::Admin);

        $this->upload($user, $this->duplicateCsv())->assertStatus(202);
        $invoice = $this->invoiceByNumber('ACME-UPS-0001');

        $writer = app(FindingWriter::class);

        $invoiceFindings = fn () => AuditFinding::withoutTenancy()
            ->where('invoice_id', $invoice->id)
            ->whereNull('invoice_line_id')
            ->get();

        // First write creates one OPEN invoice-level finding.
        $writer->write($invoice, [FindingData::make('invoice_level_rule', null, 0, 1200, 1200)]);

        $this->assertCount(1, $invoiceFindings());
        $this->assertSame(1200, (int) $invoiceFindings()->first()->recoverable_cents);

        // A re-run replaces the previous OPEN finding rather than duplicating it.
        $writer->write($invoice, [FindingData::make('invoice_level_rule', null, 0, 1300, 1300)]);

        $this->assertCount(1, $invoiceFindings());
        $this->assertSame(1300, (int) $invoiceFindings()->first()->recoverable_cents);

        // A human disputes it; a re-run must leave that row alone and, because
        // no OPEN finding remains, raise a fresh one alongside it.
        AuditFinding::withoutTenancy()->whereKey($invoiceFindings()->first()->id)->update(['status' => 'disputed']);

        $writer->write($invoice, [FindingData::make('invoice_level_rule', null, 0, 1400, 1400)]);

        $findings = $invoiceFindings();

        $this->assertCount(2, $findings);
        $this->assertSame(1300, (int) $findings->firstWhere('status', 'disputed')->recoverable_cents);
        $this->assertSame(1400, (int) $findings->firstWhere('status', 'open')->recoverable_cents);
    }

    public function test_the_seeded_acme_invoice_produces_the_planted_errors_and_the_expected_total(): void
    {
        $this->seed(DemoDataSeeder::class);

        $invoice = Invoice::withoutTenancy()
            ->where('invoice_number', 'ACME-UPS-2026-0001')
            ->firstOrFail();

        // The seeded invoice already has lines; only the audit stages need to run.
        InvoicePipeline::dispatchSync($invoice, auditOnly: true);

        $findings = AuditFinding::withoutTenancy()->where('invoice_id', $invoice->id)->get();
        $byRule = $findings->countBy('rule')->all();

        // DemoDataSeeder documents exactly these planted errors and this total.
        $this->assertSame(3, $byRule['dimensional_weight'] ?? 0);
        $this->assertSame(2, $byRule['duplicate_charge'] ?? 0);
        $this->assertSame(2, $byRule['unauthorized_surcharge'] ?? 0);
        $this->assertSame(5881, (int) $findings->sum('recoverable_cents'));
    }
}
