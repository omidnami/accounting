<?php

namespace App\Http\Requests;

use App\Models\FiscalYear;
use App\Models\Party;
use App\Models\Store;
use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManagePurchases() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $storeId = Store::query()->where('code', 'MAIN')->value('id');
        $fiscalYear = FiscalYear::query()
            ->where('store_id', $storeId)
            ->where('is_current', true)
            ->first();

        return [
            'submission_token' => ['required', 'uuid'],
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where(function ($query) use ($storeId): void {
                    $query->whereIn(
                        'party_id',
                        Party::query()
                            ->select('id')
                            ->where('store_id', $storeId)
                            ->where('is_active', true),
                    );
                }),
            ],
            'invoice_date' => [
                'required',
                'date',
                ...($fiscalYear ? [
                    'after_or_equal:'.$fiscalYear->starts_on->toDateString(),
                    'before_or_equal:'.$fiscalYear->ends_on->toDateString(),
                ] : []),
            ],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'items.*.unit_price' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,2'],
            'items.*.batch_number' => ['nullable', 'string', 'max:80'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.serial_numbers' => ['nullable', 'array', 'max:500'],
            'items.*.serial_numbers.*' => ['string', 'max:120', 'distinct'],
            'additional_costs' => ['nullable', 'array', 'max:20'],
            'additional_costs.*.description' => ['required', 'string', 'max:120'],
            'additional_costs.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
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

                foreach (['product_id', 'quantity', 'unit_price', 'discount_amount', 'tax_rate'] as $field) {
                    if (isset($item[$field]) && is_string($item[$field])) {
                        $item[$field] = trim(Digits::normalizeNumber($item[$field]));
                    }
                }

                if (isset($item['serial_numbers']) && is_string($item['serial_numbers'])) {
                    $item['serial_numbers'] = preg_split(
                        '/[\r\n,]+/',
                        trim($item['serial_numbers']),
                        -1,
                        PREG_SPLIT_NO_EMPTY,
                    );
                }

                return $item;
            }, $items);
        }
        $additionalCosts = $this->input('additional_costs');

        if (is_array($additionalCosts)) {
            $additionalCosts = array_map(function (mixed $cost): mixed {
                if (! is_array($cost)) {
                    return $cost;
                }

                if (isset($cost['amount']) && is_string($cost['amount'])) {
                    $cost['amount'] = trim(Digits::normalizeNumber($cost['amount']));
                }

                if (isset($cost['description']) && is_string($cost['description'])) {
                    $cost['description'] = trim($cost['description']);
                }

                return $cost;
            }, $additionalCosts);
        }

        $this->merge([
            'supplier_id' => is_string($this->input('supplier_id'))
                ? Digits::toLatin($this->input('supplier_id'))
                : $this->input('supplier_id'),
            'items' => $items,
            'additional_costs' => $additionalCosts,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier_id.required' => 'تأمین‌کننده فاکتور را انتخاب کنید.',
            'items.required' => 'فاکتور خرید باید حداقل یک قلم داشته باشد.',
            'items.min' => 'فاکتور خرید باید حداقل یک قلم داشته باشد.',
            'items.*.product_id.distinct' => 'هر کالا فقط یک بار می‌تواند در فاکتور باشد.',
            'items.*.quantity.gt' => 'مقدار هر قلم باید بزرگ‌تر از صفر باشد.',
            'items.*.unit_price.gt' => 'قیمت خرید هر قلم باید بزرگ‌تر از صفر باشد.',
            'items.*.tax_rate.between' => 'نرخ مالیات باید بین صفر تا صد باشد.',
            'additional_costs.*.description.required' => 'شرح هزینهٔ جانبی را وارد کنید.',
            'additional_costs.*.amount.gt' => 'مبلغ هزینهٔ جانبی باید بزرگ‌تر از صفر باشد.',
        ];
    }
}
