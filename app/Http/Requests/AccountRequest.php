<?php

namespace App\Http\Requests;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManageStoreSettings() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $storeId = Store::query()->where('code', 'MAIN')->value('id');
        $account = $this->route('account');

        return [
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:'.(string) config('accounting.account_code_pattern', '/^\d{4}$/'),
                Rule::unique('accounts', 'code')
                    ->where('store_id', $storeId)
                    ->ignore($account),
            ],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'level' => ['required', Rule::enum(AccountLevel::class)],
            'normal_balance' => ['required', Rule::enum(NormalBalance::class)],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(function ($query) use ($storeId): void {
                    $query->where('store_id', $storeId)
                        ->where('level', AccountLevel::General->value);
                }),
            ],
            'requires_party' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => is_string($this->input('code')) ? trim($this->input('code')) : '',
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : '',
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'کد حساب را وارد کنید.',
            'code.regex' => 'کد حساب باید چهار رقم و مطابق الگوی کدینگ باشد.',
            'code.unique' => 'این کد حساب در فروشگاه قبلاً ثبت شده است.',
            'parent_id.exists' => 'حساب والد باید یک حساب کل همین فروشگاه باشد.',
        ];
    }
}
