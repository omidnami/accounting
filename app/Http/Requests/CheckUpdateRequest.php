<?php

namespace App\Http\Requests;

use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;

class CheckUpdateRequest extends FormRequest
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
            'check_number' => ['required', 'string', 'max:50'],
            'bank_name' => ['required', 'string', 'max:120'],
            'branch_name' => ['nullable', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'issue_date' => ['nullable', 'date', 'before_or_equal:due_date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'check_number' => is_string($this->input('check_number'))
                ? trim(Digits::toLatin($this->input('check_number')))
                : $this->input('check_number'),
            'amount' => is_string($this->input('amount'))
                ? trim(Digits::normalizeNumber($this->input('amount')))
                : $this->input('amount'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.gt' => 'مبلغ چک باید بیشتر از صفر باشد.',
            'due_date.after_or_equal' => 'تاریخ سررسید نمی‌تواند قبل از تاریخ صدور باشد.',
        ];
    }
}
