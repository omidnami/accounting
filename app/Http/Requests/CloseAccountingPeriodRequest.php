<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CloseAccountingPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canCloseAccountingPeriods() ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['soft_closed', 'closed'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'نوع بستن دوره را انتخاب کنید.',
            'status.in' => 'نوع بستن دوره معتبر نیست.',
        ];
    }
}
