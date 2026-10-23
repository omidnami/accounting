<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManageChecks() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'settlement_date' => ['required', 'date'],
            'treasury_account_id' => ['nullable', 'integer', 'exists:treasury_accounts,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'settlement_date.required' => 'تاریخ وصول یا خرج چک را وارد کنید.',
            'treasury_account_id.exists' => 'حساب بانکی انتخاب‌شده معتبر نیست.',
        ];
    }
}
