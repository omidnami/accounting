<?php

namespace App\Http\Controllers;

use App\Enums\AccountLevel;
use App\Http\Requests\AccountRequest;
use App\Models\Account;
use App\Models\Store;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canViewFinancialReports(), 403);
        $store = $this->mainStore();
        $accounts = Account::query()
            ->where('store_id', $store->id)
            ->with('parent:id,code,name')
            ->withCount(['children', 'journalEntryLines'])
            ->orderBy('code')
            ->get();

        return Inertia::render('accounts/Index', [
            'accounts' => $accounts->map(fn (Account $account): array => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'level' => $account->level->value,
                'normal_balance' => $account->normal_balance->value,
                'parent_name' => $account->parent?->name,
                'is_active' => $account->is_active,
                'children_count' => $account->children_count,
                'usage_count' => $account->journal_entry_lines_count,
                'can_manage' => $request->user()->canManageStoreSettings(),
            ])->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canManageStoreSettings(), 403);

        return Inertia::render('accounts/Form', [
            'account' => null,
            'parents' => $this->parents(),
        ]);
    }

    public function store(AccountRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            Account::query()->create([
                ...$data,
                'store_id' => $this->mainStore()->id,
                'requires_party' => (bool) ($data['requires_party'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
        } catch (DomainException|UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['account' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'حساب جدید ایجاد شد.']);

        return to_route('accounts.index');
    }

    public function edit(Request $request, Account $account): Response
    {
        abort_unless($request->user()?->canManageStoreSettings(), 403);
        $account = $this->scopedAccount($account);

        return Inertia::render('accounts/Form', [
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'level' => $account->level->value,
                'normal_balance' => $account->normal_balance->value,
                'parent_id' => $account->parent_id,
                'requires_party' => $account->requires_party,
                'description' => $account->description,
                'is_active' => $account->is_active,
                'usage_count' => $account->journalEntryLines()->count() + $account->children()->count(),
            ],
            'parents' => $this->parents(),
        ]);
    }

    public function update(AccountRequest $request, Account $account): RedirectResponse
    {
        $account = $this->scopedAccount($account);
        $data = $request->validated();

        try {
            $account->update([
                ...$data,
                'requires_party' => (bool) ($data['requires_party'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
        } catch (DomainException|UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['account' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'حساب به‌روزرسانی شد.']);

        return to_route('accounts.index');
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }

    private function scopedAccount(Account $account): Account
    {
        abort_unless((int) $account->store_id === (int) $this->mainStore()->id, 404);

        return $account;
    }

    /** @return array<int, array{id: int, label: string}> */
    private function parents(): array
    {
        return Account::query()
            ->where('store_id', $this->mainStore()->id)
            ->where('level', AccountLevel::General)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'label' => $account->code.' — '.$account->name,
            ])->all();
    }
}
