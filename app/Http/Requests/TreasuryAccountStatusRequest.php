<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TreasuryAccountStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManageTreasury() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
            'version' => ['required', 'integer', 'min:0'],
        ];
    }
}
