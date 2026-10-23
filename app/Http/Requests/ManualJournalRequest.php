<?php

namespace App\Http\Requests;

use App\Enums\JournalEntryKind;
use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManualJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canApproveJournalEntries() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'entry_kind' => [
                'required',
                Rule::in([
                    JournalEntryKind::Manual->value,
                    JournalEntryKind::Opening->value,
                ]),
            ],
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.party_id' => ['nullable', 'integer', 'exists:parties,id'],
            'lines.*.debit' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'lines.*.credit' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');

        if (is_array($lines)) {
            $lines = array_map(function (mixed $line): mixed {
                if (! is_array($line)) {
                    return $line;
                }

                foreach (['debit', 'credit'] as $field) {
                    if (isset($line[$field]) && is_string($line[$field])) {
                        $line[$field] = trim(Digits::normalizeNumber($line[$field]));
                    }
                }

                return $line;
            }, $lines);
        }

        $this->merge([
            'lines' => $lines,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'entry_kind.required' => 'نوع سند را انتخاب کنید.',
            'entry_kind.in' => 'نوع سند باید دستی یا مانده افتتاحیه باشد.',
            'entry_date.required' => 'تاریخ سند را انتخاب کنید.',
            'description.required' => 'شرح سند را وارد کنید.',
            'lines.required' => 'حداقل دو ردیف برای سند لازم است.',
            'lines.min' => 'سند باید حداقل دو ردیف داشته باشد.',
            'lines.*.account_id.required' => 'حساب هر ردیف را انتخاب کنید.',
            'lines.*.debit.numeric' => 'مبلغ بدهکار باید عددی باشد.',
            'lines.*.credit.numeric' => 'مبلغ بستانکار باید عددی باشد.',
        ];
    }
}
