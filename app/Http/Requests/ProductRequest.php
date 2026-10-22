<?php

namespace App\Http\Requests;

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
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            'version' => $product === null ? ['nullable', 'integer', 'min:0'] : ['required', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['code', 'barcode', 'name', 'unit', 'description'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);
            $normalized[$field] = is_string($value) ? trim(Digits::toLatin($value)) : $value;

            if ($normalized[$field] === '') {
                $normalized[$field] = null;
            }
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
        ];
    }
}
