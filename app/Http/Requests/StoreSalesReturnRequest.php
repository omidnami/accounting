<?php

namespace App\Http\Requests;

use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;

class StoreSalesReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->canManageSales()
            && $user->canConfirmSales();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'submission_token' => ['required', 'uuid'],
            'return_date' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.invoice_item_id' => ['required', 'integer', 'distinct', 'exists:invoice_items,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items');

        if (is_array($items)) {
            $items = array_map(function (mixed $item): mixed {
                if (! is_array($item)) {
                    return $item;
                }

                foreach (['invoice_item_id', 'quantity'] as $field) {
                    if (isset($item[$field]) && is_string($item[$field])) {
                        $item[$field] = trim(Digits::normalizeNumber($item[$field]));
                    }
                }

                return $item;
            }, $items);
        }

        $this->merge(['items' => $items]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'حداقل یک قلم برای برگشت فروش انتخاب کنید.',
            'items.min' => 'حداقل یک قلم برای برگشت فروش انتخاب کنید.',
            'items.*.invoice_item_id.distinct' => 'یک ردیف نمی‌تواند دوبار در برگشت باشد.',
            'items.*.quantity.gt' => 'مقدار برگشت باید بزرگ‌تر از صفر باشد.',
        ];
    }
}
