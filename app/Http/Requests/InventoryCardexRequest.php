<?php

namespace App\Http\Requests;

use App\Enums\InventoryDirection;
use App\Enums\InventoryMovementType;
use App\Models\FiscalYear;
use App\Models\Store;
use App\Services\Branches\BranchContextService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InventoryCardexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canViewInventoryReports() ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $storeId = Store::query()->where('code', 'MAIN')->value('id');
        $store = $storeId !== null
            ? Store::query()->whereKey((int) $storeId)->first()
            : null;
        $branchId = $store
            ? app(BranchContextService::class)->active($this->user(), $store)->id
            : null;

        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->where(
                    fn ($query) => $query
                        ->where('store_id', $storeId)
                        ->where('branch_id', $branchId),
                ),
            ],
            'movement_type' => ['nullable', Rule::enum(InventoryMovementType::class)],
            'direction' => ['nullable', Rule::enum(InventoryDirection::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $movementType = is_string($this->input('movement_type'))
                    ? InventoryMovementType::tryFrom($this->input('movement_type'))
                    : null;
                $direction = is_string($this->input('direction'))
                    ? InventoryDirection::tryFrom($this->input('direction'))
                    : null;

                if (
                    $movementType !== null
                    && $direction !== null
                    && ! $movementType->supports($direction)
                ) {
                    $validator->errors()->add(
                        'movement_type',
                        'نوع گردش انتخاب‌شده با جهت آن سازگار نیست.',
                    );
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $storeId = Store::query()->where('code', 'MAIN')->value('id');
        $fiscalYear = FiscalYear::query()
            ->where('store_id', $storeId)
            ->where('is_current', true)
            ->first();

        $this->merge([
            'from' => $this->filled('from')
                ? $this->input('from')
                : $fiscalYear?->starts_on?->toDateString()
                    ?? CarbonImmutable::now()->startOfYear()->toDateString(),
            'to' => $this->filled('to')
                ? $this->input('to')
                : $fiscalYear?->ends_on?->toDateString()
                    ?? CarbonImmutable::now()->endOfYear()->toDateString(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.required' => 'تاریخ شروع گزارش را انتخاب کنید.',
            'to.required' => 'تاریخ پایان گزارش را انتخاب کنید.',
            'to.after_or_equal' => 'تاریخ پایان نمی‌تواند قبل از تاریخ شروع باشد.',
        ];
    }
}
