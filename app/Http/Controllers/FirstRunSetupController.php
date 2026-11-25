<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Services\Setup\FirstRunSetupService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FirstRunSetupController extends Controller
{
    use PasswordValidationRules;

    public function index(): Response
    {
        return Inertia::render('setup/Index', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'setupTokenRequired' => filled(config('app.setup_token')),
        ]);
    }

    public function store(
        Request $request,
        FirstRunSetupService $setup,
    ): RedirectResponse {
        $validated = $request->validate([
            'store_name' => ['required', 'string', 'max:150'],
            'admin_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => $this->passwordRules(),
            'timezone' => ['required', Rule::in(['Asia/Tehran', 'UTC'])],
            'currency' => ['required', Rule::in(['IRR', 'IRT'])],
        ]);

        try {
            $user = $setup->setup($validated);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['setup' => $exception->getMessage()]);
        }

        Auth::login($user);

        return redirect()->route('dashboard');
    }
}
