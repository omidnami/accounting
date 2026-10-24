<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Store;
use App\Services\Branches\BranchContextService;
use App\Services\Branches\BranchManagementService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BranchManagementController extends Controller
{
    public function index(
        Request $request,
        BranchContextService $context,
    ): Response {
        $this->authorizeManager($request);
        $store = $this->mainStore();
        $active = $context->active($request->user(), $store);
        $branches = $store->branches()
            ->withCount(['users', 'warehouses'])
            ->orderByDesc('is_default')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return Inertia::render('branches/Index', [
            'branches' => $branches->map(fn (Branch $branch): array => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
                'phone' => $branch->phone,
                'address' => $branch->address,
                'is_default' => $branch->is_default,
                'is_active' => $branch->is_active,
                'users_count' => $branch->users_count,
                'warehouses_count' => $branch->warehouses_count,
                'is_current' => $branch->id === $active->id,
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeManager($request);

        return Inertia::render('branches/Form', ['branch' => null]);
    }

    public function store(
        Request $request,
        BranchManagementService $branches,
    ): RedirectResponse {
        $this->authorizeManager($request);
        $store = $this->mainStore();

        try {
            $branch = $branches->create(
                $store,
                $this->validatedData($request, $store),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['branch' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "شعبه «{$branch->name}» و انبار پیش‌فرض آن ایجاد شد.",
        ]);

        return to_route('branches.index');
    }

    public function edit(Request $request, Branch $branch): Response
    {
        $this->authorizeManager($request);
        $this->assertBranch($branch);

        return Inertia::render('branches/Form', [
            'branch' => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
                'phone' => $branch->phone,
                'address' => $branch->address,
                'is_default' => $branch->is_default,
                'is_active' => $branch->is_active,
            ],
        ]);
    }

    public function update(
        Request $request,
        Branch $branch,
        BranchManagementService $branches,
    ): RedirectResponse {
        $this->authorizeManager($request);
        $this->assertBranch($branch);

        try {
            $updated = $branches->update(
                $branch,
                $this->validatedData($request, $this->mainStore(), $branch),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['branch' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "اطلاعات شعبه «{$updated->name}» ذخیره شد.",
        ]);

        return to_route('branches.index');
    }

    public function setDefault(
        Request $request,
        Branch $branch,
        BranchManagementService $branches,
    ): RedirectResponse {
        $this->authorizeManager($request);
        $this->assertBranch($branch);

        try {
            $branches->setDefault($branch);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['branch' => $exception->getMessage()]);
        }

        return back();
    }

    public function updateStatus(
        Request $request,
        Branch $branch,
        BranchManagementService $branches,
    ): RedirectResponse {
        $this->authorizeManager($request);
        $this->assertBranch($branch);
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        try {
            $branches->updateStatus($branch, (bool) $validated['is_active']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['branch' => $exception->getMessage()]);
        }

        return back();
    }

    /**
     * @return array{code: string, name: string, phone: string|null, address: string|null}
     */
    private function validatedData(
        Request $request,
        Store $store,
        ?Branch $branch = null,
    ): array {
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:30',
                Rule::unique('branches', 'code')
                    ->where('store_id', $store->id)
                    ->ignore($branch),
            ],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        return [
            'code' => (string) $validated['code'],
            'name' => (string) $validated['name'],
            'phone' => isset($validated['phone']) ? trim((string) $validated['phone']) : null,
            'address' => isset($validated['address']) ? trim((string) $validated['address']) : null,
        ];
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless($request->user()?->canManageBranches(), 403);
    }

    private function assertBranch(Branch $branch): void
    {
        abort_unless((int) $branch->store_id === (int) $this->mainStore()->id, 404);
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }
}
