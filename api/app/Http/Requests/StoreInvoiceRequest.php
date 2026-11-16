<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // CSV or TXT, capped at 5 MB (5120 KB).
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'carrier' => ['required', 'string', 'exists:carriers,code'],
            'invoice_number' => ['required', 'string', 'max:255'],
            'invoice_date' => ['required', 'date'],
        ];
    }
}
