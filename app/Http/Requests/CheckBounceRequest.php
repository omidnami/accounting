<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckBounceRequest extends FormRequest
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
            'bounce_date' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bounce_date.required' => 'تاریخ برگشت چک را وارد کنید.',
        ];
    }
}
