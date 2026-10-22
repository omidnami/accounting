<?php

namespace App\Http\Requests;

use App\Models\FiscalYear;
use App\Models\Party;
use App\Models\Store;
use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManagePayments() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $storeId = Store::query()->where('code', 'MAIN')->value('id');
        $fiscalYear = FiscalYear::query()
            ->where('store_id', $storeId)
            ->where('is_current', true)
            ->first();

        return [
            'submission_token' => ['required', 'uuid'],
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where(function ($query) use ($storeId): void {
                    $query->whereIn(
                        'party_id',
                        Party::query()
                            ->select('id')
                            ->where('store_id', $storeId)
                            ->where('is_active', true),
                    );
                }),
            ],
            'payment_date' => [
                'required',
                'date',
                ...($fiscalYear ? [
                    'after_or_equal:'.$fiscalYear->starts_on->toDateString(),
                    'before_or_equal:'.$fiscalYear->ends_on->toDateString(),
                ] : []),
            ],
            'payment_method' => ['required', 'string', 'in:cash,bank,card'],
            'treasury_account_id' => ['nullable', 'integer', 'exists:treasury_accounts,id'],
            'reference' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'allocations' => ['required', 'array', 'min:1', 'max:50'],
            'allocations.*.invoice_id' => ['required', 'integer', 'distinct', 'exists:invoices,id'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $allocations = $this->input('allocations');

        if (is_array($allocations)) {
            $allocations = array_map(function (mixed $allocation): mixed {
                if (! is_array($allocation)) {
                    return $allocation;
                }

                foreach (['invoice_id', 'amount'] as $field) {
                    if (isset($allocation[$field]) && is_string($allocation[$field])) {
                        $allocation[$field] = trim(Digits::normalizeNumber($allocation[$field]));
                    }
                }

                return $allocation;
            }, $allocations);
        }

        $this->merge([
            'supplier_id' => is_string($this->input('supplier_id'))
                ? Digits::toLatin($this->input('supplier_id'))
                : $this->input('supplier_id'),
            'allocations' => $allocations,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier_id.required' => 'تأمین‌کننده پرداخت را انتخاب کنید.',
            'allocations.required' => 'حداقل یک فاکتور برای پرداخت انتخاب کنید.',
            'allocations.min' => 'حداقل یک فاکتور برای پرداخت انتخاب کنید.',
            'allocations.*.invoice_id.distinct' => 'یک فاکتور نمی‌تواند دوبار در پرداخت باشد.',
            'allocations.*.amount.gt' => 'مبلغ تخصیص باید بزرگ‌تر از صفر باشد.',
        ];
    }
}
