<?php

namespace App\Services\Pos;

use App\Enums\InvoiceType;
use App\Enums\JournalEntryKind;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PosSale;
use App\Models\PosTender;
use App\Models\Receipt;
use App\Models\User;
use App\Services\Accounting\Contracts\PostingAuthorizationContext;
use App\Support\Decimal;

/** A scope for generated POS documents; never grants access to manual journals. */
final readonly class PosPostingContext implements PostingAuthorizationContext
{
    public function __construct(public int $saleId) {}

    public function allows(JournalEntry $entry, User $user): bool
    {
        $sale = PosSale::query()->with(['session', 'invoice'])->find($this->saleId);

        if (! $sale || ! $sale->invoice || $sale->completed_at !== null
            || $sale->session->closed_at !== null
            || $user->role !== UserRole::Cashier
            || (int) $sale->session->user_id !== (int) $user->id
            || (int) $entry->created_by !== (int) $user->id
            || (int) $entry->store_id !== (int) $sale->session->store_id
            || (int) $entry->branch_id !== (int) $sale->session->branch_id
            || (int) $entry->fiscal_year_id !== (int) $sale->invoice->fiscal_year_id
            || $entry->entry_kind !== JournalEntryKind::System
            || $entry->reversal_of_id !== null) {
            return false;
        }

        if ($entry->source_type === $sale->invoice->getMorphClass()) {
            return (int) $entry->source_id === (int) $sale->invoice_id
                && (int) $sale->invoice->created_by === (int) $user->id
                && $sale->invoice->type === InvoiceType::Sales;
        }

        if ($entry->source_type !== (new Receipt)->getMorphClass()) {
            return false;
        }

        $receipt = Receipt::query()->with('allocations')->find($entry->source_id);

        return $receipt !== null
            && $receipt->allocations->count() === 1
            && (int) $receipt->allocations->first()->invoice_id === (int) $sale->invoice_id
            && (int) $receipt->created_by === (int) $user->id
            && $sale->tenders()->where('receipt_id', $receipt->id)
                ->whereIn('method', ['cash', 'card'])->exists();
    }

    public function immediateAmount(Invoice $invoice): string
    {
        $sale = PosSale::query()->findOrFail($this->saleId);

        if ((int) $sale->invoice_id !== (int) $invoice->id || $sale->completed_at !== null) {
            throw new \DomainException('فروش صندوق با فاکتور سازگار نیست.');
        }

        return $sale->tenders()->whereIn('method', ['cash', 'card'])
            ->get()->reduce(fn (string $sum, $tender): string => Decimal::add($sum, $tender->amount, 2), '0.00');
    }

    public function prepareTreasuryDocument(Receipt $receipt, User $user, ?int $tenderId = null): void
    {
        $tender = PosTender::query()
            ->whereKey($tenderId)
            ->where('pos_sale_id', $this->saleId)
            ->lockForUpdate()
            ->firstOrFail();
        $sale = PosSale::query()->with('session')->findOrFail($this->saleId);

        if ($sale->session->closed_at !== null
            || (int) $sale->session->user_id !== (int) $user->id
            || $tender->method !== $receipt->payment_method->value
            || Decimal::compare($tender->amount, $receipt->amount, 2) !== 0
            || $tender->receipt_id !== null) {
            throw new \DomainException('پرداخت صندوق با دریافت سازگار نیست.');
        }

        $tender->update(['receipt_id' => $receipt->id]);
    }
}
