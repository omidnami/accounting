<?php

namespace App\Http\Requests;

use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;

class StoreFiscalYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManageFiscalYears() ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'between:1200,1700'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $year = $this->input('year');

        $this->merge([
            'year' => is_string($year)
                ? Digits::toLatin(trim($year))
                : $year,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'year.required' => 'سال شمسی را وارد کنید.',
            'year.integer' => 'سال شمسی باید یک عدد صحیح باشد.',
            'year.between' => 'سال شمسی باید بین ۱۲۰۰ و ۱۷۰۰ باشد.',
        ];
    }
}
