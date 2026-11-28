<?php

namespace App\Http\Requests;

use App\Models\Invoice;

class UpdateSalesInvoiceRequest extends SalesInvoiceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['submission_token']);

        $invoice = $this->route('invoice');

        if ($invoice instanceof Invoice && $invoice->fiscalYear !== null) {
            $rules['invoice_date'] = [
                'required',
                'date',
                'after_or_equal:'.$invoice->fiscalYear->starts_on->toDateString(),
                'before_or_equal:'.$invoice->fiscalYear->ends_on->toDateString(),
            ];
        }

        return $rules;
    }
}
