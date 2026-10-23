<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FiscalYearClosingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->canCloseFiscalYears()
            && $user->canReopenFiscalYears();
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
            'reason.required' => 'دلیل بستن یا بازگشایی سال را وارد کنید.',
            'reason.min' => 'دلیل باید حداقل پنج نویسه باشد.',
        ];
    }
}
