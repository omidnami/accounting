<?php

namespace App\Http\Requests;

use App\Models\Check;
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
        $check = $this->route('check');
        $check = $check instanceof Check ? $check : null;
        $rules = [
            'settlement_date' => ['required', 'date'],
            'treasury_account_id' => ['nullable', 'integer', 'exists:treasury_accounts,id'],
        ];

        if ($check?->issue_date !== null) {
            $rules['settlement_date'][] = 'after_or_equal:'.$check->issue_date->toDateString();
        }

        return $rules;
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
