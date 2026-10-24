<?php

namespace App\Http\Requests;

use App\Models\Store;
use App\Services\Branches\BranchContextService;
use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManageStockCounts() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $store = Store::query()->where('code', 'MAIN')->firstOrFail();
        $branch = app(BranchContextService::class)
            ->active($this->user(), $store);

        return [
            'submission_token' => ['required', 'uuid'],
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')
                    ->where('store_id', $store->id)
                    ->where('branch_id', $branch->id),
            ],
            'count_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.counted_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,3'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
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

                foreach (['counted_quantity', 'unit_cost'] as $field) {
                    if (isset($item[$field]) && is_string($item[$field])) {
                        $item[$field] = trim(Digits::normalizeNumber($item[$field]));
                    }
                }

                return $item;
            }, $items);
        }

        $this->merge([
            'items' => $items,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'warehouse_id.required' => 'انبار شمارش را انتخاب کنید.',
            'count_date.required' => 'تاریخ شمارش را انتخاب کنید.',
            'items.required' => 'حداقل یک کالا برای شمارش انتخاب کنید.',
            'items.*.product_id.distinct' => 'هر کالا فقط یک بار در شمارش قابل ثبت است.',
            'items.*.counted_quantity.min' => 'مقدار شمارش‌شده نمی‌تواند منفی باشد.',
        ];
    }
}
