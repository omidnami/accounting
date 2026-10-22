<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmSalesInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManageSales() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
        ];
    }
}
