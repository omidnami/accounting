<?php

namespace App\Http\Requests;

use App\Enums\TreasuryAccountType;
use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TreasuryAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManageTreasury() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:30',
                'regex:/^[A-Za-z0-9_-]+$/',
            ],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(TreasuryAccountType::class)],
            'gl_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'is_default' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'version' => ['required', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => is_string($this->input('code'))
                ? strtoupper(trim(Digits::toLatin($this->input('code'))))
                : $this->input('code'),
            'gl_account_id' => is_string($this->input('gl_account_id'))
                ? Digits::toLatin($this->input('gl_account_id'))
                : $this->input('gl_account_id'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'کد داخلی حساب خزانه را وارد کنید.',
            'code.regex' => 'کد حساب فقط می‌تواند شامل حروف انگلیسی، عدد، خط تیره و زیرخط باشد.',
            'gl_account_id.required' => 'حساب معین دفتر کل را انتخاب کنید.',
            'gl_account_id.exists' => 'حساب دفتر کل انتخاب‌شده معتبر نیست.',
        ];
    }
}
