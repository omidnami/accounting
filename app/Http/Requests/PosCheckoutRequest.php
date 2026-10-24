<?php

namespace App\Http\Requests;

use App\Support\Digits;

class PosCheckoutRequest extends SalesInvoiceRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['invoice_date']);
        $rules['customer_id'][0] = 'nullable';

        return [
            ...$rules,
            'session_id' => ['required', 'integer', 'exists:pos_sessions,id'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'tenders' => ['required', 'array', 'min:1', 'max:20'],
            'tenders.*.method' => ['required', 'in:cash,card,check,credit'],
            'tenders.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'tenders.*.confirmed' => ['sometimes', 'boolean'],
            'tenders.*.reference' => ['nullable', 'string', 'max:100'],
            'tenders.*.check_received' => ['sometimes', 'boolean'],
            'tenders.*.check_number' => ['nullable', 'string', 'max:100'],
            'tenders.*.bank_name' => ['nullable', 'string', 'max:100'],
            'tenders.*.due_date' => ['nullable', 'date'],
            'tenders.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $tenders = $this->input('tenders');
        if (is_array($tenders)) {
            $tenders = array_map(function (mixed $tender): mixed {
                if (is_array($tender) && isset($tender['amount']) && is_string($tender['amount'])) {
                    $tender['amount'] = Digits::normalizeNumber($tender['amount']);
                }

                return $tender;
            }, $tenders);
        }
        $this->merge(['tenders' => $tenders]);
    }
}
