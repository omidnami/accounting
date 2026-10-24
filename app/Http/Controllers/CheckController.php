<?php

namespace App\Http\Controllers;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\TreasuryAccountType;
use App\Http\Requests\CheckBounceRequest;
use App\Http\Requests\CheckRequest;
use App\Http\Requests\CheckSettlementRequest;
use App\Models\Branch;
use App\Models\Check;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\TreasuryAccount;
use App\Services\Branches\BranchContextService;
use App\Services\Treasury\CheckRegistrationService;
use App\Services\Treasury\CheckSettlementService;
use App\Support\Digits;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CheckController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canManageChecks(), 403);

        $store = $this->mainStore();
        $branch = $this->defaultBranch($store);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(CheckType::class)],
            'status' => ['nullable', Rule::enum(CheckStatus::class)],
            'due_state' => ['nullable', 'string', 'in:overdue,due_soon'],
        ]);
        $search = trim(Digits::toLatin((string) ($validated['search'] ?? '')));
        $type = $validated['type'] ?? null;
        $status = $validated['status'] ?? null;
        $dueState = $validated['due_state'] ?? null;
        $base = Check::query()
            ->where('store_id', $store->id)
            ->where('branch_id', $branch->id);
        $checks = (clone $base)
            ->with([
                'customer.party:id,code,name,phone',
                'supplier.party:id,code,name,phone',
                'receipt:id,receipt_number,status,check_id',
                'payment:id,payment_number,status,check_id',
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query
                        ->where('check_number', 'like', $term)
                        ->orWhere('bank_name', 'like', $term)
                        ->orWhere('branch_name', 'like', $term)
                        ->orWhereHas('customer.party', fn (Builder $query) => $query
                            ->where('name', 'like', $term)
                            ->orWhere('code', 'like', $term))
                        ->orWhereHas('supplier.party', fn (Builder $query) => $query
                            ->where('name', 'like', $term)
                            ->orWhere('code', 'like', $term));
                });
            })
            ->when($type !== null, fn (Builder $query) => $query->where('type', $type))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($dueState === 'overdue', fn (Builder $query) => $query
                ->where('status', CheckStatus::Pending)
                ->whereDate('due_date', '<', today()))
            ->when($dueState === 'due_soon', fn (Builder $query) => $query
                ->where('status', CheckStatus::Pending)
                ->whereDate('due_date', '>=', today())
                ->whereDate('due_date', '<=', today()->addDays(7)))
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Check $check): array => $this->mapCheckSummary($check));

        return Inertia::render('checks/Index', [
            'checks' => $checks,
            'filters' => [
                'search' => $search,
                'type' => $type,
                'status' => $status,
                'due_state' => $dueState,
            ],
            'summary' => [
                'total' => (clone $base)->count(),
                'pending' => (clone $base)->where('status', CheckStatus::Pending)->count(),
                'pending_amount' => (string) (clone $base)
                    ->where('status', CheckStatus::Pending)
                    ->sum('amount'),
                'overdue' => (clone $base)
                    ->where('status', CheckStatus::Pending)
                    ->whereDate('due_date', '<', today())
                    ->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canManageChecks(), 403);

        $validated = $request->validate([
            'type' => ['nullable', Rule::enum(CheckType::class)],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'return_to' => ['nullable', 'string', 'in:receipt,payment'],
            'invoice_ids' => [
                'nullable',
                'string',
                'max:1000',
                'regex:/^\d+(,\d+)*$/',
            ],
        ]);
        $store = $this->mainStore();
        $type = isset($validated['type'])
            ? CheckType::from($validated['type'])
            : CheckType::Receivable;
        $initialParty = null;

        if ($type === CheckType::Receivable && isset($validated['customer_id'])) {
            $customer = Customer::query()
                ->with('party')
                ->whereKey((int) $validated['customer_id'])
                ->where('is_active', true)
                ->whereHas('party', fn (Builder $query) => $query
                    ->where('store_id', $store->id)
                    ->where('is_active', true))
                ->firstOrFail();
            $initialParty = [
                'id' => $customer->id,
                'code' => $customer->party->code,
                'name' => $customer->party->name,
                'phone' => $customer->party->phone,
            ];
        }

        if ($type === CheckType::Payable && isset($validated['supplier_id'])) {
            $supplier = Supplier::query()
                ->with('party')
                ->whereKey((int) $validated['supplier_id'])
                ->where('is_active', true)
                ->whereHas('party', fn (Builder $query) => $query
                    ->where('store_id', $store->id)
                    ->where('is_active', true))
                ->firstOrFail();
            $initialParty = [
                'id' => $supplier->id,
                'code' => $supplier->party->code,
                'name' => $supplier->party->name,
                'phone' => $supplier->party->phone,
            ];
        }

        return Inertia::render('checks/Create', [
            'defaults' => [
                'type' => $type->value,
                'issue_date' => today()->toDateString(),
                'due_date' => today()->addDays(30)->toDateString(),
                'submission_token' => (string) Str::uuid(),
            ],
            'initial_party' => $initialParty,
            'return_context' => isset($validated['return_to'])
                ? [
                    'kind' => $validated['return_to'],
                    'invoice_ids' => $validated['invoice_ids'] ?? '',
                ]
                : null,
        ]);
    }

    public function store(
        CheckRequest $request,
        CheckRegistrationService $checks,
    ): RedirectResponse {
        $store = $this->mainStore();
        $branch = $this->defaultBranch($store);
        $fiscalYear = $this->currentFiscalYear($store);
        $data = $request->validated();
        $type = CheckType::from($data['type']);
        $partyProfileId = $type === CheckType::Receivable
            ? (int) $data['customer_id']
            : (int) $data['supplier_id'];

        try {
            $check = $checks->register(
                storeId: $store->id,
                branchId: $branch->id,
                fiscalYearId: $fiscalYear->id,
                type: $type,
                partyProfileId: $partyProfileId,
                checkNumber: $data['check_number'],
                bankName: $data['bank_name'],
                branchName: $data['branch_name'] ?? null,
                amount: $data['amount'],
                issueDate: $data['issue_date'] ?? null,
                dueDate: $data['due_date'],
                creator: $request->user(),
                idempotencyKey: $data['submission_token'],
                description: $data['description'] ?? null,
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'check' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "چک {$check->document_number} ثبت شد.",
        ]);

        if (($data['return_to'] ?? null) === 'receipt'
            && $type === CheckType::Receivable) {
            return to_route('receipts.create', [
                'customer_id' => $partyProfileId,
                'check_id' => $check->id,
                'invoice_ids' => $data['invoice_ids'] ?? null,
            ]);
        }

        if (($data['return_to'] ?? null) === 'payment'
            && $type === CheckType::Payable) {
            return to_route('payments.create', [
                'supplier_id' => $partyProfileId,
                'check_id' => $check->id,
                'invoice_ids' => $data['invoice_ids'] ?? null,
            ]);
        }

        return to_route('checks.show', $check);
    }

    public function show(Request $request, Check $check): Response
    {
        abort_unless($request->user()?->canManageChecks(), 403);
        abort_unless(
            (int) $check->store_id === $this->mainStore()->id
            && (int) $check->branch_id === $this->defaultBranch($this->mainStore())->id,
            404,
        );
        $check->load([
            'customer.party',
            'supplier.party',
            'creator:id,name',
            'receipt.customer.party',
            'receipt.allocations.invoice',
            'payment.supplier.party',
            'payment.allocations.invoice',
            'settlementEntry',
            'settlementTreasuryAccount',
            'bounceEntry',
        ]);

        $treasuryDocument = null;

        if ($check->receipt) {
            $treasuryDocument = [
                'kind' => 'receipt',
                'id' => $check->receipt->id,
                'number' => $check->receipt->receipt_number,
                'date' => $check->receipt->receipt_date->toDateString(),
                'amount' => $check->receipt->amount,
                'status' => $check->receipt->status->value,
                'invoices' => $check->receipt->allocations->map(fn ($allocation): array => [
                    'id' => $allocation->invoice->id,
                    'invoice_number' => $allocation->invoice->invoice_number,
                    'amount' => $allocation->amount,
                ])->values(),
            ];
        } elseif ($check->payment) {
            $treasuryDocument = [
                'kind' => 'payment',
                'id' => $check->payment->id,
                'number' => $check->payment->payment_number,
                'date' => $check->payment->payment_date->toDateString(),
                'amount' => $check->payment->amount,
                'status' => $check->payment->status->value,
                'invoices' => $check->payment->allocations->map(fn ($allocation): array => [
                    'id' => $allocation->invoice->id,
                    'invoice_number' => $allocation->invoice->invoice_number,
                    'amount' => $allocation->amount,
                ])->values(),
            ];
        }

        return Inertia::render('checks/Show', [
            'check' => [
                ...$this->mapCheckSummary($check),
                'branch_name' => $check->branch_name,
                'description' => $check->description,
                'creator' => $check->creator?->name,
                'created_at' => $check->created_at?->toDateTimeString(),
                'settled_at' => $check->settled_at?->toDateTimeString(),
                'bounced_at' => $check->bounced_at?->toDateTimeString(),
                'treasury_document' => $treasuryDocument,
                'settlement_treasury_account' => $check->settlementTreasuryAccount?->name,
                'settlement_treasury_account_code' => $check->settlementTreasuryAccount?->code,
                'entries' => [
                    'settlement' => $this->mapEntry($check->settlementEntry),
                    'bounce' => $this->mapEntry($check->bounceEntry),
                ],
            ],
            'bank_accounts' => $this->bankAccounts($check->store_id, $check->branch_id),
            'defaults' => [
                'action_date' => today()->toDateString(),
            ],
        ]);
    }

    public function settle(
        CheckSettlementRequest $request,
        Check $check,
        CheckSettlementService $settlements,
    ): RedirectResponse {
        abort_unless(
            (int) $check->store_id === $this->mainStore()->id
            && (int) $check->branch_id === $this->defaultBranch($this->mainStore())->id,
            404,
        );
        $data = $request->validated();

        try {
            if ($check->type === CheckType::Receivable) {
                $settlements->collectReceivable(
                    $check,
                    $data['settlement_date'],
                    $request->user(),
                    isset($data['treasury_account_id'])
                        ? (int) $data['treasury_account_id']
                        : null,
                );
                $message = "وصول چک {$check->check_number} ثبت شد.";
            } else {
                $settlements->settlePayable(
                    $check,
                    $data['settlement_date'],
                    $request->user(),
                    isset($data['treasury_account_id'])
                        ? (int) $data['treasury_account_id']
                        : null,
                );
                $message = "خرج چک {$check->check_number} ثبت شد.";
            }
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'check' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $message,
        ]);

        return to_route('checks.show', $check);
    }

    public function bounce(
        CheckBounceRequest $request,
        Check $check,
        CheckSettlementService $settlements,
    ): RedirectResponse {
        abort_unless(
            (int) $check->store_id === $this->mainStore()->id
            && (int) $check->branch_id === $this->defaultBranch($this->mainStore())->id,
            404,
        );
        $data = $request->validated();

        try {
            $settlements->bounce(
                $check,
                $data['bounce_date'],
                $request->user(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'check' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "برگشت چک {$check->check_number} ثبت شد.",
        ]);

        return to_route('checks.show', $check);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCheckSummary(Check $check): array
    {
        $isReceivable = $check->type === CheckType::Receivable;
        $party = $isReceivable
            ? $check->customer?->party
            : $check->supplier?->party;

        return [
            'id' => $check->id,
            'document_number' => $check->document_number,
            'check_number' => $check->check_number,
            'bank_name' => $check->bank_name,
            'amount' => $check->amount,
            'issue_date' => $check->issue_date?->toDateString(),
            'due_date' => $check->due_date->toDateString(),
            'type' => $check->type->value,
            'status' => $check->status->value,
            'party' => [
                'kind' => $isReceivable ? 'customer' : 'supplier',
                'id' => $party?->id,
                'code' => $party?->code,
                'name' => $party?->name,
            ],
            'treasury_document' => $check->receipt
                ? [
                    'kind' => 'receipt',
                    'id' => $check->receipt->id,
                    'number' => $check->receipt->receipt_number,
                    'status' => $check->receipt->status->value,
                ]
                : ($check->payment
                    ? [
                        'kind' => 'payment',
                        'id' => $check->payment->id,
                        'number' => $check->payment->payment_number,
                        'status' => $check->payment->status->value,
                    ]
                    : null),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapEntry(?JournalEntry $entry): ?array
    {
        if (! $entry) {
            return null;
        }

        return [
            'id' => $entry->id,
            'number' => $entry->entry_number,
            'date' => $entry->entry_date->toDateString(),
            'description' => $entry->description,
            'status' => $entry->status->value,
        ];
    }

    /**
     * @return array<int, array{id: int, name: string, code: string, is_default: bool}>
     */
    private function bankAccounts(int $storeId, int $branchId): array
    {
        return TreasuryAccount::query()
            ->where('store_id', $storeId)
            ->where('branch_id', $branchId)
            ->where('type', TreasuryAccountType::Bank)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_default'])
            ->map(fn (TreasuryAccount $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'code' => $account->code,
                'is_default' => $account->is_default,
            ])
            ->all();
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }

    private function defaultBranch(Store $store): Branch
    {
        return app(BranchContextService::class)->active(request()->user(), $store);
    }

    private function currentFiscalYear(Store $store): FiscalYear
    {
        return $store->fiscalYears()
            ->where('is_current', true)
            ->firstOrFail();
    }
}
