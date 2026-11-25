<?php

namespace App\Http\Requests;

use App\Models\Check;
use Carbon\CarbonImmutable;
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
        $check = $this->route('check');
        $check = $check instanceof Check ? $check : null;
        $documentDate = $check?->issue_date;

        if ($check !== null) {
            $receiptDate = $check->receipt()->value('receipt_date');
            $paymentDate = $check->payment()->value('payment_date');
            $documentDate = $receiptDate instanceof CarbonImmutable
                ? $receiptDate
                : ($paymentDate instanceof CarbonImmutable ? $paymentDate : $check->issue_date);
        }

        $rules = ['bounce_date' => ['required', 'date']];

        if ($documentDate !== null) {
            $rules['bounce_date'][] = 'after_or_equal:'.$documentDate->toDateString();
        }

        return $rules;
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
