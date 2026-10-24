<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\UserManagementRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\Users\UserManagementService;
use App\Support\Digits;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserManagementController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canManageUsers(), 403);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
        ]);
        $search = trim(Digits::toLatin((string) ($validated['search'] ?? '')));
        $role = $validated['role'] ?? null;
        $base = User::query();
        $users = (clone $base)
            ->with('branch:id,code,name')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(fn (Builder $query) => $query
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->when($role !== null, fn (Builder $query) => $query->where('role', $role))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'branch' => $user->branch
                    ? [
                        'id' => $user->branch->id,
                        'code' => $user->branch->code,
                        'name' => $user->branch->name,
                    ]
                    : null,
                'email_verified' => $user->email_verified_at !== null,
                'created_at' => $user->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('users/Index', [
            'users' => $users,
            'filters' => [
                'search' => $search,
                'role' => $role,
            ],
            'summary' => [
                'total' => (clone $base)->count(),
                'managers' => (clone $base)->where('role', UserRole::Manager)->count(),
                'accountants' => (clone $base)->where('role', UserRole::Accountant)->count(),
                'operators' => (clone $base)->whereIn('role', [
                    UserRole::Cashier,
                    UserRole::Warehouse,
                ])->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canManageUsers(), 403);

        return Inertia::render('users/Form', [
            'user' => null,
            'branches' => $this->branches(),
        ]);
    }

    public function store(
        UserManagementRequest $request,
        UserManagementService $users,
    ): RedirectResponse {
        try {
            $user = $users->create($this->userData($request->validated()));
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'user' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "کاربر «{$user->name}» ایجاد شد.",
        ]);

        return to_route('users.index');
    }

    public function edit(Request $request, User $user): Response
    {
        abort_unless($request->user()?->canManageUsers(), 403);

        return Inertia::render('users/Form', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'branch_id' => $user->branch_id,
                'version' => $user->version,
                'is_self' => (int) $user->id === (int) $request->user()->id,
            ],
            'branches' => $this->branches(),
        ]);
    }

    public function update(
        UserManagementRequest $request,
        User $user,
        UserManagementService $users,
    ): RedirectResponse {
        try {
            $updated = $users->update(
                $user,
                $this->userData($request->validated()),
                $request->user(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'user' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "اطلاعات کاربر «{$updated->name}» ذخیره شد.",
        ]);

        return to_route('users.index');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     name: string, email: string, role: string, branch_id: int|null,
     *     password: string, version: int
     * }
     */
    private function userData(array $data): array
    {
        return [
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
            'role' => (string) $data['role'],
            'branch_id' => isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            'password' => (string) ($data['password'] ?? ''),
            'version' => (int) $data['version'],
        ];
    }

    /**
     * @return Collection<int, array{id: int<0, max>, code: string, name: string}>
     */
    private function branches(): Collection
    {
        return Store::query()
            ->where('code', 'MAIN')
            ->firstOrFail()
            ->branches()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn ($branch): array => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
            ])
            ->values();
    }
}
