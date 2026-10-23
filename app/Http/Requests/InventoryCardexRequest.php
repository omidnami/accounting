<?php

namespace App\Http\Requests;

use App\Enums\InventoryDirection;
use App\Enums\InventoryMovementType;
use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->where(
                    fn ($query) => $query->where('store_id', $storeId),
                ),
            ],
            'movement_type' => ['nullable', Rule::enum(InventoryMovementType::class)],
            'direction' => ['nullable', Rule::enum(InventoryDirection::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
