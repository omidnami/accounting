<?php

namespace App\Http\Controllers;

use App\Http\Requests\PosCheckoutRequest;
use App\Models\FiscalYear;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\PosTender;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\TreasuryAccount;
use App\Services\Branches\BranchContextService;
use App\Services\Pos\PosCheckoutService;
use App\Services\Pos\PosCheckResolutionService;
use App\Services\Pos\PosSessionService;
use App\Services\Sales\CustomerReceivableService;
use App\Services\Sales\SalesCheckoutLookupService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function index(Request $request, PosSessionService $sessions): Response
    {
        abort_unless($request->user()?->canManageSales(), 403);
        $store = Store::query()->where('code', 'MAIN')->firstOrFail();
        $branch = app(BranchContextService::class)->active($request->user(), $store);
        $session = PosSession::query()->where('active_user_id', $request->user()->id)->first();

        return Inertia::render('pos/Index', [
            'store_name' => $store->name,
            'today' => today()->toDateString(),
            'session' => $session ? $this->sessionData($session, $sessions) : null,
            'warehouses' => $branch->warehouses()->where('is_active', true)->orderByDesc('is_default')->get(['id', 'name']),
            'treasury_accounts' => TreasuryAccount::query()->where('branch_id', $branch->id)
                ->where('is_active', true)->whereIn('type', ['cash', 'card'])
                ->orderByDesc('is_default')->get(['id', 'name', 'type']),
            'categories' => ProductCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'can_review' => $request->user()->canManageChecks(),
            'submission_token' => (string) Str::uuid(),
            'fiscal_year' => FiscalYear::query()->where('store_id', $store->id)->where('is_current', true)->value('title'),
        ]);
    }

    public function open(Request $request, PosSessionService $sessions): JsonResponse
    {
        abort_unless($request->user()?->canManageSales(), 403);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'cash_account_id' => ['required', 'integer', 'exists:treasury_accounts,id'],
            'card_account_id' => ['nullable', 'integer', 'exists:treasury_accounts,id'],
            'opening_cash' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ]);
        $store = Store::query()->where('code', 'MAIN')->firstOrFail();
        $branch = app(BranchContextService::class)->active($request->user(), $store);
        try {
            $session = $sessions->open($branch, $request->user(), (int) $data['warehouse_id'], (int) $data['cash_account_id'],
                isset($data['card_account_id']) ? (int) $data['card_account_id'] : null, (string) $data['opening_cash']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['pos' => $exception->getMessage()]);
        }

        return response()->json(['session' => $this->sessionData($session, $sessions)]);
    }

    public function close(Request $request, PosSession $session, PosSessionService $sessions): JsonResponse
    {
        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        try {
            $sessions->close($session, $request->user(), (string) $data['counted_cash'], $data['notes'] ?? null);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['pos' => $exception->getMessage()]);
        }

        return response()->json(['closed' => true]);
    }

    public function checkout(PosCheckoutRequest $request, PosCheckoutService $checkout): JsonResponse
    {
        $data = $request->validated();
        $session = PosSession::query()->findOrFail((int) $data['session_id']);
        try {
            $sale = $checkout->checkout($session, $request->user(), $data);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['pos' => $exception->getMessage()]);
        }

        return response()->json(['sale' => $this->saleData($sale)]);
    }

    public function products(Request $request, SalesCheckoutLookupService $lookup): JsonResponse
    {
        abort_unless($request->user()?->canManageSales(), 403);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'category_id' => ['nullable', 'integer']]);
        $session = PosSession::query()->where('active_user_id', $request->user()->id)->firstOrFail();

        return response()->json(['data' => $lookup->products($session->store_id, $session->warehouse_id,
            (string) ($data['search'] ?? ''), isset($data['category_id']) ? (int) $data['category_id'] : null)]);
    }

    public function attempt(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canManageSales(), 403);
        $data = $request->validate(['token' => ['required', 'uuid']]);
        $sale = PosSale::query()->where('submission_token', $data['token'])
            ->whereHas('session', fn ($query) => $query->where('user_id', $request->user()->id))
            ->whereNotNull('completed_at')->with(['invoice.customer.party', 'tenders'])->first();

        return response()->json(['sale' => $sale ? $this->saleData($sale) : null]);
    }

    public function activity(Request $request, PosSessionService $sessions): JsonResponse
    {
        abort_unless($request->user()?->canManageSales(), 403);
        $session = PosSession::query()->where('active_user_id', $request->user()->id)->first();
        $storeId = Store::query()->where('code', 'MAIN')->value('id');
        $pending = $request->user()->canManageChecks()
            ? PosTender::query()->where('method', 'check')->whereNull('resolved_at')
                ->whereHas('sale.session', fn ($query) => $query->where('store_id', $storeId))
                ->with(['sale.invoice.customer.party', 'sale.session.user'])->orderBy('id')->limit(100)->get()
                ->map(fn (PosTender $tender): array => [
                    'id' => $tender->id,
                    'amount' => $tender->amount,
                    'check_received' => $tender->check_received,
                    'check_number' => $tender->check_number,
                    'bank_name' => $tender->bank_name,
                    'due_date' => $tender->due_date?->toDateString(),
                    'notes' => $tender->notes,
                    'invoice_number' => $tender->sale->invoice->invoice_number,
                    'customer_name' => $tender->sale->invoice->customer->party->name,
                    'cashier_name' => $tender->sale->session->user->name,
                ])->all()
            : [];

        return response()->json([
            'session' => $session ? $this->sessionData($session, $sessions) : null,
            'recent_sales' => $session ? $session->sales()->whereNotNull('completed_at')->with(['invoice.customer.party', 'tenders'])
                ->latest('id')->limit(20)->get()->map(fn (PosSale $sale): array => $this->saleData($sale))->all() : [],
            'pending_checks' => $pending,
        ]);
    }

    public function resolve(Request $request, PosTender $tender, PosCheckResolutionService $checks): JsonResponse
    {
        abort_unless($request->user()?->canManageChecks(), 403);
        abort_unless((int) $tender->sale->session->store_id === (int) Store::query()->where('code', 'MAIN')->value('id'), 404);
        $data = $request->validate([
            'check_number' => ['required', 'string', 'max:100'],
            'bank_name' => ['required', 'string', 'max:100'],
            'due_date' => ['required', 'date'],
            'receipt_date' => ['required', 'date', 'after_or_equal:'.$tender->sale->invoice->invoice_date->toDateString()],
        ]);
        try {
            $checks->resolve($tender, $request->user(), $data);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['pos' => $exception->getMessage()]);
        }

        return response()->json(['registered' => true]);
    }

    /** @return array<string, mixed> */
    private function sessionData(PosSession $session, PosSessionService $sessions): array
    {
        return [
            'id' => $session->id,
            'warehouse_id' => $session->warehouse_id,
            'cash_account_id' => $session->cash_account_id,
            'card_account_id' => $session->card_account_id,
            'opening_cash' => $session->opening_cash,
            'expected_cash' => $sessions->expectedCash($session),
            'opened_at' => $session->opened_at->toIso8601String(),
            'sales_count' => $session->sales()->whereNotNull('completed_at')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function saleData(PosSale $sale): array
    {
        $invoice = $sale->invoice;

        return [
            'id' => $sale->id,
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'customer_name' => $invoice->customer->party->name,
            'total' => $invoice->total,
            'status' => $invoice->status->value,
            'outstanding' => app(CustomerReceivableService::class)->outstandingForInvoice($invoice),
            'pending_check' => $sale->tenders->where('method', 'check')->whereNull('resolved_at')->sum('amount'),
        ];
    }
}
