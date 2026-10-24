<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManageUsers() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = $this->route('user');
        $passwordRule = Password::defaults();
        $storeId = Store::query()->where('code', 'MAIN')->value('id');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($target),
            ],
            'role' => ['required', Rule::enum(UserRole::class)],
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')
                    ->where('store_id', $storeId)
                    ->where('is_active', true),
            ],
            'password' => [
                $target ? 'nullable' : 'required',
                'confirmed',
                $passwordRule,
            ],
            'version' => ['required', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $role = UserRole::tryFrom((string) $this->input('role'));
        $branchId = $this->input('branch_id');

        $this->merge([
            'email' => is_string($this->input('email'))
                ? strtolower(trim($this->input('email')))
                : $this->input('email'),
            'branch_id' => $branchId !== null && $branchId !== ''
                ? $branchId
                : (in_array($role, [UserRole::Cashier, UserRole::Warehouse], true)
                    ? Branch::query()
                        ->whereHas('store', fn ($query) => $query->where('code', 'MAIN'))
                        ->where('is_active', true)
                        ->orderByDesc('is_default')
                        ->value('id')
                    : null),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'این ایمیل قبلاً برای کاربر دیگری ثبت شده است.',
            'role.required' => 'نقش کاربر را انتخاب کنید.',
            'branch_id.exists' => 'شعبهٔ انتخاب‌شده معتبر یا فعال نیست.',
            'password.required' => 'برای کاربر جدید رمز عبور تعیین کنید.',
            'password.confirmed' => 'تکرار رمز عبور مطابقت ندارد.',
        ];
    }
}
