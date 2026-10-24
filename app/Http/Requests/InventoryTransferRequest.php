<?php

namespace App\Http\Requests;

use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;

class InventoryTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canTransferInventory() ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'submission_token' => ['required', 'uuid'],
            'source_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'transfer_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $quantity = $this->input('quantity');

        $this->merge([
            'quantity' => is_string($quantity)
                ? trim(Digits::normalizeNumber($quantity))
                : $quantity,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source_warehouse_id.required' => 'انبار مبدأ را انتخاب کنید.',
            'destination_warehouse_id.required' => 'انبار مقصد را انتخاب کنید.',
            'product_id.required' => 'کالای انتقال را انتخاب کنید.',
            'quantity.gt' => 'مقدار انتقال باید بزرگ‌تر از صفر باشد.',
            'transfer_date.required' => 'تاریخ انتقال را انتخاب کنید.',
        ];
    }
}
