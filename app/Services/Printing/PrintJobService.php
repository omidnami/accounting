<?php

namespace App\Services\Printing;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\PrintJob;
use App\Models\PrintStation;
use DomainException;
use Illuminate\Support\Str;

class PrintJobService
{
    public function queueSalesInvoice(Invoice $invoice): PrintJob
    {
        if ($invoice->type !== InvoiceType::Sales) {
            throw new DomainException('فقط فاکتور فروش برای چاپ خودکار قابل استفاده است.');
        }

        if ($invoice->status === InvoiceStatus::Draft) {
            throw new DomainException('پیش‌نویس فاکتور قابل ارسال به Print Agent نیست.');
        }

        $station = PrintStation::query()
            ->where('store_id', $invoice->store_id)
            ->where('branch_id', $invoice->branch_id)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->latest('last_seen_at')
            ->first();

        if (! $station) {
            throw new DomainException('Print Agent فعالی برای این فروشگاه ثبت نشده است.');
        }

        $invoice->load(['items', 'customer.party', 'branch', 'store']);

        return PrintJob::query()->create([
            'id' => (string) Str::uuid(),
            'store_id' => $invoice->store_id,
            'branch_id' => $invoice->branch_id,
            'print_station_id' => $station->id,
            'document_type' => 'sales_invoice',
            'document_id' => $invoice->id,
            'template' => 'sales_receipt',
            'template_version' => 1,
            'payload' => [
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date->toDateString(),
                'seller' => [
                    'name' => $invoice->store?->legal_name ?: $invoice->store?->name,
                    'phone' => $invoice->store?->phone,
                    'address' => $invoice->store?->address,
                    'branch' => $invoice->branch?->name,
                ],
                'customer' => [
                    'name' => $invoice->customer?->party?->name,
                    'phone' => $invoice->customer?->party?->phone,
                ],
                'items' => $invoice->items->map(fn ($item): array => [
                    'name' => $item->product_name,
                    'code' => $item->product_code,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                ])->values()->all(),
                'totals' => [
                    'subtotal' => $invoice->subtotal,
                    'discount' => $invoice->discount_total,
                    'tax' => $invoice->tax_total,
                    'total' => $invoice->total,
                ],
            ],
            'paper_width_mm' => (int) ($invoice->branch->thermal_paper_width ?? 80),
            'copies' => (int) ($invoice->branch->thermal_copies ?? 1),
            'status' => 'queued',
            'expires_at' => now()->addMinutes(10),
        ]);
    }
}
