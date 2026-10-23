<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReopenAccountingPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canReopenAccountingPeriods() ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'دلیل بازگشایی دوره را وارد کنید.',
            'reason.min' => 'دلیل بازگشایی باید حداقل پنج نویسه باشد.',
        ];
    }
}
