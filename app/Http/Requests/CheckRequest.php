<?php

namespace App\Http\Requests;

use App\Enums\CheckType;
use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckRequest extends FormRequest
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
        $isReceivable = $this->input('type') === CheckType::Receivable->value;

        return [
            'submission_token' => ['required', 'uuid'],
            'return_to' => ['nullable', 'string', 'in:receipt,payment'],
            'invoice_ids' => [
                'nullable',
                'string',
                'max:1000',
                'regex:/^\d+(,\d+)*$/',
            ],
            'type' => ['required', Rule::enum(CheckType::class)],
            'customer_id' => [
                Rule::requiredIf($isReceivable),
                Rule::prohibitedIf(! $isReceivable),
                'nullable',
                'integer',
                'exists:customers,id',
            ],
            'supplier_id' => [
                Rule::requiredIf(! $isReceivable),
                Rule::prohibitedIf($isReceivable),
                'nullable',
                'integer',
                'exists:suppliers,id',
            ],
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
            'customer_id' => is_string($this->input('customer_id'))
                ? Digits::toLatin($this->input('customer_id'))
                : $this->input('customer_id'),
            'supplier_id' => is_string($this->input('supplier_id'))
                ? Digits::toLatin($this->input('supplier_id'))
                : $this->input('supplier_id'),
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
            'customer_id.required' => 'برای چک دریافتی، مشتری را انتخاب کنید.',
            'supplier_id.required' => 'برای چک پرداختی، تأمین‌کننده را انتخاب کنید.',
            'amount.gt' => 'مبلغ چک باید بیشتر از صفر باشد.',
            'due_date.after_or_equal' => 'تاریخ سررسید نمی‌تواند قبل از تاریخ صدور باشد.',
        ];
    }
}
