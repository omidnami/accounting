<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\ReturnRefund;
use App\Models\TreasuryAccount;
use App\Services\Returns\ReturnRefundService;
use App\Support\BusinessClock;
use App\Support\Decimal;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ReturnRefundController extends Controller
{
    public function create(
        Request $request,
        Invoice $invoice,
        BusinessClock $clock,
    ): Response {
        $this->authorizeRefund($request, $invoice);
        $refunded = (string) ReturnRefund::query()
            ->where('source_invoice_id', $invoice->id)
            ->where('status', 'posted')
            ->sum('amount');
        $party = $invoice->partyIdentity();

        return Inertia::render('returns/Refund', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->invoice_number,
                'type' => $invoice->type->value,
                'total' => $invoice->total,
                'remaining' => Decimal::subtract($invoice->total, $refunded, Decimal::MONEY_SCALE),
                'party' => [
                    'name' => $party['name'],
                    'code' => $party['code'],
                    'phone' => $party['phone'],
                    'legacy_current' => $party['legacy_current'] ?? false,
                ],
            ],
            'treasury_accounts' => TreasuryAccount::query()
                ->where('store_id', $invoice->store_id)
                ->where('branch_id', $invoice->branch_id)
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->get(['id', 'name', 'type'])
                ->map(fn (TreasuryAccount $account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'type' => $account->type->value,
                ]),
            'default_date' => $clock->today($invoice->store_id)->toDateString(),
        ]);
    }

    public function store(
        Request $request,
        Invoice $invoice,
        ReturnRefundService $refunds,
    ): RedirectResponse {
        $this->authorizeRefund($request, $invoice);
        $validated = $request->validate([
            'treasury_account_id' => ['required', 'integer', 'exists:treasury_accounts,id'],
            'refund_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'submission_token' => ['required', 'uuid'],
        ]);

        try {
            $refunds->refund(
                $invoice,
                (int) $validated['treasury_account_id'],
                $validated['refund_date'],
                $validated['amount'],
                $request->user(),
                $validated['submission_token'],
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['refund' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'refund و سند خزانهٔ آن ثبت شد.']);

        return to_route($invoice->type === InvoiceType::SalesReturn ? 'sales.show' : 'purchases.show', $invoice);
    }

    private function authorizeRefund(Request $request, Invoice $invoice): void
    {
        $allowed = $invoice->type === InvoiceType::SalesReturn
            ? $request->user()?->canManageReceipts()
            : ($invoice->type === InvoiceType::PurchaseReturn && $request->user()?->canManagePayments());
        abort_unless($allowed && $invoice->status === InvoiceStatus::Confirmed, 403);
    }
}
