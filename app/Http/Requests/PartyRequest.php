<?php

namespace App\Http\Requests;

use App\Enums\PartyEntityType;
use App\Models\Party;
use App\Models\Store;
use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManageParties() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Party|null $party */
        $party = $this->route('party');
        $roles = $this->input('roles');
        $roles = is_array($roles) ? $roles : [];
        $hasCustomer = in_array('customer', $roles, true);
        $hasSupplier = in_array('supplier', $roles, true);
        $storeId = Store::query()
            ->where('code', 'MAIN')
            ->value('id');

        return [
            'code' => [
                'required',
                'string',
                'max:30',
                'regex:/^[\p{L}\p{N}][\p{L}\p{N}_-]*$/u',
                Rule::unique('parties', 'code')
                    ->where(fn ($query) => $query->where('store_id', $storeId))
                    ->ignore($party?->id),
            ],
            'entity_type' => ['required', Rule::enum(PartyEntityType::class)],
            'name' => ['required', 'string', 'max:180'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:20'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            'version' => $party === null ? ['nullable', 'integer', 'min:0'] : ['required', 'integer', 'min:0'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(['customer', 'supplier'])],
            'customer.credit_limit' => $hasCustomer ? [
                'nullable',
                'numeric',
                'min:0',
                'max:999999999999999',
                'decimal:0,2',
            ] : ['exclude'],
            'customer.payment_terms' => $hasCustomer ? ['nullable', 'string', 'max:100'] : ['exclude'],
            'customer.sales_notes' => $hasCustomer ? ['nullable', 'string', 'max:2000'] : ['exclude'],
            'supplier.payment_terms' => $hasSupplier ? ['nullable', 'string', 'max:100'] : ['exclude'],
            'supplier.lead_time_days' => $hasSupplier ? ['nullable', 'integer', 'min:0', 'max:3650'] : ['exclude'],
            'supplier.purchasing_notes' => $hasSupplier ? ['nullable', 'string', 'max:2000'] : ['exclude'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['code', 'name', 'phone', 'email', 'national_id', 'tax_id', 'address'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);
            $normalized[$field] = is_string($value) ? trim(Digits::toLatin($value)) : $value;

            if ($normalized[$field] === '') {
                $normalized[$field] = null;
            }
        }

        foreach (['customer.payment_terms', 'customer.sales_notes', 'supplier.payment_terms', 'supplier.purchasing_notes'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);
            $normalized[$field] = is_string($value) ? trim($value) : $value;

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
            'code.regex' => 'کد طرف‌حساب فقط می‌تواند شامل حروف، اعداد، خط تیره و زیرخط باشد.',
            'roles.required' => 'حداقل یک نقش مشتری یا تأمین‌کننده را انتخاب کنید.',
            'roles.min' => 'حداقل یک نقش مشتری یا تأمین‌کننده را انتخاب کنید.',
            'roles.*.in' => 'نقش انتخاب‌شده معتبر نیست.',
            'customer.credit_limit.decimal' => 'سقف اعتبار حداکثر می‌تواند دو رقم اعشار داشته باشد.',
            'customer.credit_limit.min' => 'سقف اعتبار نمی‌تواند منفی باشد.',
            'supplier.lead_time_days.integer' => 'زمان تأمین باید عدد صحیح باشد.',
        ];
    }
}
