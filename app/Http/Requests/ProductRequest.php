<?php

namespace App\Http\Requests;

use App\Enums\ProductStockPolicy;
use App\Models\Product;
use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManageProducts() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Product|null $product */
        $product = $this->route('product');

        return [
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'parent_product_id' => [
                'nullable',
                'integer',
                'exists:products,id',
                Rule::notIn([$product?->id]),
            ],
            'variant_color' => ['nullable', 'string', 'max:80'],
            'variant_size' => ['nullable', 'string', 'max:80'],
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[\p{L}\p{N}][\p{L}\p{N}_-]*$/u',
                Rule::unique('products', 'code')->ignore($product?->id),
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:64',
                'regex:/^[\p{L}\p{N}][\p{L}\p{N}_-]*$/u',
                Rule::unique('products', 'barcode')->ignore($product?->id),
            ],
            'name' => ['required', 'string', 'max:180'],
            'unit' => ['required', 'string', 'max:30'],
            'purchase_price' => ['required', 'numeric', 'min:0', 'max:9999999999999999', 'decimal:0,2'],
            'sale_price' => ['required', 'numeric', 'min:0', 'max:9999999999999999', 'decimal:0,2'],
            'tax_rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'reorder_level' => ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,3'],
            'safety_stock' => ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,3'],
            'reorder_quantity' => ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,3'],
            'max_stock' => ['nullable', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,3', 'gte:reorder_level'],
            'stock_policy' => ['required', Rule::enum(ProductStockPolicy::class)],
            'track_batches' => ['required', 'boolean'],
            'track_serials' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            'version' => $product === null ? ['nullable', 'integer', 'min:0'] : ['required', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'category_id' => null,
            'parent_product_id' => null,
            'variant_color' => null,
            'variant_size' => null,
            'safety_stock' => '0',
            'reorder_quantity' => '0',
            'max_stock' => null,
            'stock_policy' => 'simple',
            'track_batches' => false,
            'track_serials' => false,
        ]);
        $normalized = [];

        foreach (['code', 'barcode', 'name', 'unit', 'description', 'variant_color', 'variant_size'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);
            $normalized[$field] = is_string($value) ? trim(Digits::toLatin($value)) : $value;

            if ($normalized[$field] === '') {
                $normalized[$field] = null;
            }
        }

        foreach (['purchase_price', 'sale_price', 'tax_rate', 'reorder_level', 'safety_stock', 'reorder_quantity', 'max_stock'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);
            $normalized[$field] = is_string($value)
                ? trim(Digits::normalizeNumber($value))
                : $value;
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'کد کالا فقط می‌تواند شامل حروف، اعداد، خط تیره و زیرخط باشد.',
            'barcode.regex' => 'بارکد فقط می‌تواند شامل حروف، اعداد، خط تیره و زیرخط باشد.',
            'tax_rate.between' => 'نرخ مالیات باید بین صفر تا صد باشد.',
            'reorder_level.decimal' => 'نقطه سفارش حداکثر می‌تواند سه رقم اعشار داشته باشد.',
            'max_stock.gte' => 'حداکثر موجودی نمی‌تواند کمتر از نقطه سفارش باشد.',
            'parent_product_id.not_in' => 'کالا نمی‌تواند والد خودش باشد.',
        ];
    }
}
