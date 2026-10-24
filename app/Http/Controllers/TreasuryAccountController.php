<?php

namespace App\Http\Controllers;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\TreasuryAccountType;
use App\Enums\TreasuryDocumentStatus;
use App\Http\Requests\TreasuryAccountRequest;
use App\Http\Requests\TreasuryAccountStatusRequest;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Store;
use App\Models\TreasuryAccount;
use App\Services\Branches\BranchContextService;
use App\Services\Treasury\TreasuryAccountService;
use App\Support\Digits;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TreasuryAccountController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canManageTreasury(), 403);

        $store = $this->mainStore();
        $branch = $this->defaultBranch($store);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(TreasuryAccountType::class)],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);
        $search = trim(Digits::toLatin((string) ($validated['search'] ?? '')));
        $type = $validated['type'] ?? null;
        $status = $validated['status'] ?? null;
        $base = TreasuryAccount::query()
            ->where('store_id', $store->id)
            ->where('branch_id', $branch->id);
        $accounts = (clone $base)
            ->with('glAccount:id,code,name')
            ->withCount([
                'receipts as pending_receipts_count' => fn (Builder $query) => $query
                    ->where('status', TreasuryDocumentStatus::Pending),
                'payments as pending_payments_count' => fn (Builder $query) => $query
                    ->where('status', TreasuryDocumentStatus::Pending),
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query
                        ->where('code', 'like', $term)
                        ->orWhere('name', 'like', $term)
                        ->orWhereHas('glAccount', fn (Builder $query) => $query
                            ->where('name', 'like', $term)
                            ->orWhere('code', 'like', $term));
                });
            })
            ->when($type !== null, fn (Builder $query) => $query->where('type', $type))
            ->when($status !== null, fn (Builder $query) => $query->where('is_active', $status === 'active'))
            ->orderBy('type')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (TreasuryAccount $account): array => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'is_default' => $account->is_default,
                'is_active' => $account->is_active,
                'version' => $account->version,
                'gl_account' => [
                    'code' => $account->glAccount->code,
                    'name' => $account->glAccount->name,
                ],
                'pending_documents' => $account->pending_receipts_count
                    + $account->pending_payments_count,
            ]);

        return Inertia::render('treasury-accounts/Index', [
            'accounts' => $accounts,
            'filters' => [
                'search' => $search,
                'type' => $type,
                'status' => $status,
            ],
            'summary' => [
                'total' => (clone $base)->count(),
                'active' => (clone $base)->where('is_active', true)->count(),
                'cash' => (clone $base)
                    ->where('type', TreasuryAccountType::Cash)
                    ->where('is_active', true)
                    ->count(),
                'bank' => (clone $base)
                    ->where('type', TreasuryAccountType::Bank)
                    ->where('is_active', true)
                    ->count(),
            ],
            'branch' => [
                'name' => $branch->name,
                'code' => $branch->code,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canManageTreasury(), 403);

        return Inertia::render('treasury-accounts/Form', [
            'account' => null,
            'gl_accounts' => $this->glAccounts($this->mainStore()),
            'gl_account_codes' => config('accounting.treasury_account_gl_codes'),
        ]);
    }

    public function store(
        TreasuryAccountRequest $request,
        TreasuryAccountService $treasuryAccounts,
    ): RedirectResponse {
        $store = $this->mainStore();
        $branch = $this->defaultBranch($store);

        try {
            $account = $treasuryAccounts->create(
                $store,
                $branch,
                $this->accountData($request->validated()),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'treasury_account' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "حساب خزانه «{$account->name}» ایجاد شد.",
        ]);

        return to_route('treasury-accounts.index');
    }

    public function edit(Request $request, TreasuryAccount $treasuryAccount): Response
    {
        abort_unless($request->user()?->canManageTreasury(), 403);
        $this->assertAccountScope($treasuryAccount);
        $treasuryAccount->load('glAccount');

        return Inertia::render('treasury-accounts/Form', [
            'account' => [
                'id' => $treasuryAccount->id,
                'code' => $treasuryAccount->code,
                'name' => $treasuryAccount->name,
                'type' => $treasuryAccount->type->value,
                'gl_account_id' => $treasuryAccount->gl_account_id,
                'is_default' => $treasuryAccount->is_default,
                'is_active' => $treasuryAccount->is_active,
                'version' => $treasuryAccount->version,
                'has_usage' => $treasuryAccount->receipts()->exists()
                    || $treasuryAccount->payments()->exists(),
            ],
            'gl_accounts' => $this->glAccounts($this->mainStore()),
            'gl_account_codes' => config('accounting.treasury_account_gl_codes'),
        ]);
    }

    public function update(
        TreasuryAccountRequest $request,
        TreasuryAccount $treasuryAccount,
        TreasuryAccountService $treasuryAccounts,
    ): RedirectResponse {
        $this->assertAccountScope($treasuryAccount);

        try {
            $account = $treasuryAccounts->update(
                $treasuryAccount,
                $this->accountData($request->validated()),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'treasury_account' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "حساب خزانه «{$account->name}» ذخیره شد.",
        ]);

        return to_route('treasury-accounts.index');
    }

    public function status(
        TreasuryAccountStatusRequest $request,
        TreasuryAccount $treasuryAccount,
        TreasuryAccountService $treasuryAccounts,
    ): RedirectResponse {
        $this->assertAccountScope($treasuryAccount);

        try {
            $account = $treasuryAccounts->setActive(
                $treasuryAccount,
                (bool) $request->validated('is_active'),
                (int) $request->validated('version'),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'treasury_account' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $account->is_active
                ? "حساب «{$account->name}» فعال شد."
                : "حساب «{$account->name}» غیرفعال شد.",
        ]);

        return to_route('treasury-accounts.index');
    }

    /**
     * @return array<int, array{id: int, code: string, name: string}>
     */
    private function glAccounts(Store $store): array
    {
        return Account::query()
            ->where('store_id', $store->id)
            ->where('level', AccountLevel::Subsidiary)
            ->where('type', AccountType::Asset)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
            ])
            ->all();
    }

    private function assertAccountScope(TreasuryAccount $account): void
    {
        abort_unless(
            (int) $account->store_id === $this->mainStore()->id
            && (int) $account->branch_id === (int) $this->defaultBranch($this->mainStore())->id,
            404,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     code: string, name: string, type: string, gl_account_id: int,
     *     is_default: bool, is_active: bool, version: int
     * }
     */
    private function accountData(array $data): array
    {
        return [
            'code' => (string) $data['code'],
            'name' => (string) $data['name'],
            'type' => (string) $data['type'],
            'gl_account_id' => (int) $data['gl_account_id'],
            'is_default' => (bool) $data['is_default'],
            'is_active' => (bool) $data['is_active'],
            'version' => (int) $data['version'],
        ];
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }

    private function defaultBranch(Store $store): Branch
    {
        return app(BranchContextService::class)->active(request()->user(), $store);
    }
}
