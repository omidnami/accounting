<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
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
        $this->merge([
            'email' => is_string($this->input('email'))
                ? strtolower(trim($this->input('email')))
                : $this->input('email'),
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
            'password.required' => 'برای کاربر جدید رمز عبور تعیین کنید.',
            'password.confirmed' => 'تکرار رمز عبور مطابقت ندارد.',
        ];
    }
}
