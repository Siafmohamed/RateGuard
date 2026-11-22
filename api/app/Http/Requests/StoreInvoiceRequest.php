<?php

namespace App\Http\Requests;

use App\Domain\Invoices\Support\InvoiceCsvHeader;
use App\Models\Carrier;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a carrier invoice upload.
 *
 * Authorization (admin|owner) is enforced by the controller through
 * InvoicePolicy, so it is deliberately not duplicated here.
 */
class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|Rule>>
     */
    public function rules(): array
    {
        return [
            // CSV or TXT, capped at 5 MB (5120 KB).
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'carrier' => ['required', 'string', 'exists:carriers,code'],
            'invoice_number' => [
                'required',
                'string',
                'max:255',
                // An invoice is unique per organization and carrier, matching
                // the invoices_organization_id_carrier_id_invoice_number_unique
                // index. Validating here turns a repeat upload into a 422 with a
                // clear message instead of an unhandled duplicate-key 500.
                Rule::unique('invoices', 'invoice_number')
                    ->where('organization_id', app(CurrentOrganization::class)->id())
                    ->where('carrier_id', Carrier::query()
                        ->where('code', $this->input('carrier'))
                        ->value('id')),
            ],
            'invoice_date' => ['required', 'date'],
        ];
    }

    /**
     * Reject incompatible CSVs before the upload is stored or an invoice row
     * is created. The queued parser repeats this check when processing rows.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('file')) {
                    return;
                }

                $file = $this->file('file');

                if (! $file instanceof UploadedFile || ! $this->hasExpectedHeader($file)) {
                    $validator->errors()->add(
                        'file',
                        'The invoice CSV must have this header row: '.InvoiceCsvHeader::expected().'.',
                    );
                }
            },
        ];
    }

    private function hasExpectedHeader(UploadedFile $file): bool
    {
        $path = $file->getRealPath();

        if (! is_string($path)) {
            return false;
        }

        $stream = @fopen($path, 'rb');

        if (! is_resource($stream)) {
            return false;
        }

        try {
            while (($cells = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if ($cells === [null] || $cells === []) {
                    continue;
                }

                return InvoiceCsvHeader::matches($cells);
            }
        } finally {
            fclose($stream);
        }

        return false;
    }
}
