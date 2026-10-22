<?php

namespace App\Http\Controllers;

use App\Http\Requests\PartyRequest;
use App\Models\Customer;
use App\Models\Party;
use App\Models\Store;
use App\Models\Supplier;
use App\Support\Digits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PartyController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canManageParties(), 403);

        $store = $this->mainStore();
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'in:customer,supplier'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);
        $search = trim(Digits::toLatin((string) ($validated['search'] ?? '')));
        $role = $validated['role'] ?? null;
        $status = $validated['status'] ?? null;
        $baseQuery = Party::query()->where('store_id', $store->id);

        $parties = (clone $baseQuery)
            ->with([
                'customer:id,party_id,is_active',
                'supplier:id,party_id,is_active',
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

                $query->where(function (Builder $query) use ($term): void {
                    $query
                        ->where('code', 'like', $term)
                        ->orWhere('name', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('national_id', 'like', $term);
                });
            })
            ->when($role === 'customer', fn (Builder $query) => $query->whereHas('customer'))
            ->when($role === 'supplier', fn (Builder $query) => $query->whereHas('supplier'))
            ->when($status !== null, fn (Builder $query) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Party $party): array => [
                'id' => $party->id,
                'code' => $party->code,
                'name' => $party->name,
                'entity_type' => $party->entity_type->value,
                'phone' => $party->phone,
                'email' => $party->email,
                'is_active' => $party->is_active,
                'roles' => array_values(array_filter([
                    $party->customer !== null ? 'customer' : null,
                    $party->supplier !== null ? 'supplier' : null,
                ])),
                'inactive_roles' => array_values(array_filter([
                    $party->customer !== null && ! $party->customer->is_active ? 'customer' : null,
                    $party->supplier !== null && ! $party->supplier->is_active ? 'supplier' : null,
                ])),
            ]);

        return Inertia::render('parties/Index', [
            'parties' => $parties,
            'filters' => [
                'search' => $search,
                'role' => $role,
                'status' => $status,
            ],
            'summary' => [
                'total' => (clone $baseQuery)->count(),
                'active' => (clone $baseQuery)->where('is_active', true)->count(),
                'customers' => (clone $baseQuery)
                    ->where('is_active', true)
                    ->whereHas('customer', fn (Builder $query) => $query->where('is_active', true))
                    ->count(),
                'suppliers' => (clone $baseQuery)
                    ->where('is_active', true)
                    ->whereHas('supplier', fn (Builder $query) => $query->where('is_active', true))
                    ->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canManageParties(), 403);

        return Inertia::render('parties/Form', [
            'party' => null,
        ]);
    }

    public function store(PartyRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $party = DB::transaction(function () use ($data): Party {
                $party = Party::query()->create([
                    ...collect($data)->only([
                        'code',
                        'entity_type',
                        'name',
                        'phone',
                        'email',
                        'national_id',
                        'tax_id',
                        'address',
                        'is_active',
                    ])->all(),
                    'store_id' => $this->mainStore()->id,
                ]);

                $this->syncProfiles($party, $data);

                return $party;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isPartyCodeConflict($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'code' => 'این کد طرف‌حساب قبلاً در فروشگاه ثبت شده است.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "طرف‌حساب «{$party->name}» با موفقیت ایجاد شد.",
        ]);

        return to_route('parties.index');
    }

    public function edit(Request $request, Party $party): Response
    {
        abort_unless($request->user()?->canManageParties(), 403);
        abort_unless($party->store_id === $this->mainStore()->id, 404);

        $party->load(['customer', 'supplier']);

        return Inertia::render('parties/Form', [
            'party' => [
                'id' => $party->id,
                'code' => $party->code,
                'entity_type' => $party->entity_type->value,
                'name' => $party->name,
                'phone' => $party->phone,
                'email' => $party->email,
                'national_id' => $party->national_id,
                'tax_id' => $party->tax_id,
                'address' => $party->address,
                'is_active' => $party->is_active,
                'version' => $party->version,
                'customer' => $party->customer === null ? null : [
                    'credit_limit' => $party->customer->credit_limit,
                    'payment_terms' => $party->customer->payment_terms,
                    'sales_notes' => $party->customer->sales_notes,
                    'is_active' => $party->customer->is_active,
                ],
                'supplier' => $party->supplier === null ? null : [
                    'payment_terms' => $party->supplier->payment_terms,
                    'lead_time_days' => $party->supplier->lead_time_days,
                    'purchasing_notes' => $party->supplier->purchasing_notes,
                    'is_active' => $party->supplier->is_active,
                ],
            ],
        ]);
    }

    public function update(PartyRequest $request, Party $party): RedirectResponse
    {
        abort_unless($party->store_id === $this->mainStore()->id, 404);
        $data = $request->validated();

        try {
            DB::transaction(function () use ($party, $data): void {
                $lockedParty = Party::query()
                    ->whereKey($party->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedParty->version !== (int) $data['version']) {
                    throw ValidationException::withMessages([
                        'party' => 'این طرف‌حساب هم‌زمان توسط کاربر دیگری تغییر کرده است. اطلاعات تازه را دوباره بررسی کنید.',
                    ]);
                }

                $lockedParty->update([
                    ...collect($data)->only([
                        'code',
                        'entity_type',
                        'name',
                        'phone',
                        'email',
                        'national_id',
                        'tax_id',
                        'address',
                        'is_active',
                    ])->all(),
                    'version' => $lockedParty->version + 1,
                ]);

                $this->syncProfiles($lockedParty, $data);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isPartyCodeConflict($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'code' => 'این کد طرف‌حساب قبلاً در فروشگاه ثبت شده است.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "تغییرات طرف‌حساب «{$party->name}» ذخیره شد.",
        ]);

        return to_route('parties.index');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncProfiles(Party $party, array $data): void
    {
        /** @var array<int, string> $roles */
        $roles = $data['roles'];
        $customerData = is_array($data['customer'] ?? null) ? $data['customer'] : [];
        $supplierData = is_array($data['supplier'] ?? null) ? $data['supplier'] : [];

        if (in_array('customer', $roles, true)) {
            Customer::query()->updateOrCreate(
                ['party_id' => $party->id],
                [
                    'credit_limit' => $customerData['credit_limit'] ?? null,
                    'payment_terms' => $customerData['payment_terms'] ?? null,
                    'sales_notes' => $customerData['sales_notes'] ?? null,
                    'is_active' => true,
                ],
            );
        } else {
            Customer::query()
                ->where('party_id', $party->id)
                ->update(['is_active' => false]);
        }

        if (in_array('supplier', $roles, true)) {
            Supplier::query()->updateOrCreate(
                ['party_id' => $party->id],
                [
                    'payment_terms' => $supplierData['payment_terms'] ?? null,
                    'lead_time_days' => $supplierData['lead_time_days'] ?? null,
                    'purchasing_notes' => $supplierData['purchasing_notes'] ?? null,
                    'is_active' => true,
                ],
            );
        } else {
            Supplier::query()
                ->where('party_id', $party->id)
                ->update(['is_active' => false]);
        }
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }

    private function isPartyCodeConflict(UniqueConstraintViolationException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'parties_store_id_code_unique')
            || (str_contains($message, 'parties') && str_contains($message, 'code'));
    }
}
