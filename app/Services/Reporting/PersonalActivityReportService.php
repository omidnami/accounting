<?php

namespace App\Services\Reporting;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class PersonalActivityReportService
{
    private const ENTITY_LABELS = [
        'Account' => 'حساب',
        'AccountingPeriod' => 'دوره مالی',
        'Branch' => 'شعبه',
        'Check' => 'چک',
        'FiscalYear' => 'سال مالی',
        'InventoryMovement' => 'گردش انبار',
        'InventoryTransfer' => 'انتقال انبار',
        'Invoice' => 'فاکتور',
        'InvoiceItem' => 'ردیف فاکتور',
        'JournalEntry' => 'سند حسابداری',
        'Party' => 'طرف‌حساب',
        'Payment' => 'پرداخت',
        'PaymentAllocation' => 'تخصیص پرداخت',
        'Product' => 'کالا',
        'Receipt' => 'دریافت',
        'ReceiptAllocation' => 'تخصیص دریافت',
        'StockCount' => 'شمارش انبار',
        'StockCountItem' => 'ردیف شمارش انبار',
        'TreasuryAccount' => 'حساب خزانه',
        'User' => 'حساب کاربری',
        'PosSession' => 'شیفت صندوق',
    ];

    private const EVENT_LABELS = [
        'created' => 'ایجاد',
        'updated' => 'ویرایش',
        'deleted' => 'حذف',
        'period_closed' => 'بستن دوره مالی',
        'period_reopened' => 'بازگشایی دوره مالی',
        'fiscal_year_closed' => 'بستن سال مالی',
        'fiscal_year_reopened' => 'بازگشایی سال مالی',
        'fiscal_year_closing_posted' => 'ثبت اختتامیه',
        'fiscal_year_closing_reversed' => 'برگشت اختتامیه',
        'pos.opened' => 'بازکردن شیفت صندوق',
        'pos.closed' => 'بستن شیفت صندوق',
        'pos.sale' => 'ثبت فروش صندوق',
        'pos.check_registered' => 'ثبت چک صندوق',
    ];

    private const FIELD_LABELS = [
        'name' => 'نام', 'title' => 'عنوان', 'email' => 'ایمیل',
        'status' => 'وضعیت', 'is_active' => 'فعال بودن', 'role' => 'نقش کاربر',
        'quantity' => 'تعداد', 'unit_price' => 'قیمت واحد', 'sale_price' => 'قیمت فروش',
        'purchase_price' => 'قیمت خرید', 'discount_amount' => 'تخفیف',
        'tax_rate' => 'نرخ مالیات', 'total' => 'جمع مبلغ', 'amount' => 'مبلغ',
        'invoice_date' => 'تاریخ فاکتور', 'entry_date' => 'تاریخ سند',
        'due_date' => 'سررسید', 'notes' => 'یادداشت', 'description' => 'شرح',
        'warehouse_id' => 'انبار', 'customer_id' => 'مشتری', 'supplier_id' => 'تأمین‌کننده',
        'counted_quantity' => 'تعداد شمارش‌شده', 'payment_method' => 'روش پرداخت',
    ];

    /**
     * @return LengthAwarePaginator<int, array{
     *     id: int, event_label: string, entity_label: string, entity_id: int,
     *     description: string, created_at: string, reference: string|null,
     *     record_name: string|null, changed_fields: list<string>, ip_address: string|null
     * }>
     */
    public function paginate(User $user, ?string $date = null): LengthAwarePaginator
    {
        $recent = AuditLog::query()
            ->where('user_id', $user->id)
            ->when($date === 'today', fn (Builder $query) => $query->whereDate('created_at', today()))
            ->when($date === '7_days', fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(7)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50);

        return AuditLog::query()->fromSub($recent, 'audit_logs')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(function (AuditLog $log): array {
                $model = class_basename($log->auditable_type);
                $entity = self::ENTITY_LABELS[$model] ?? 'رکورد';
                $event = self::EVENT_LABELS[$log->event] ?? 'ثبت رویداد';
                $description = $log->description === $model.' '.$log->event
                    ? $event.' '.$entity
                    : $log->description;

                return [
                    'id' => $log->id,
                    'event_label' => $event,
                    'entity_label' => $entity,
                    'entity_id' => $log->auditable_id,
                    'description' => $description,
                    'created_at' => $log->created_at->toIso8601String(),
                    'reference' => $this->detail($log, [
                        'invoice_number', 'entry_number', 'receipt_number', 'payment_number',
                        'check_number', 'count_number', 'transfer_number', 'code',
                    ]),
                    'record_name' => $this->detail($log, ['product_name', 'name', 'title']),
                    'changed_fields' => $this->changedFields($log),
                    'ip_address' => $log->ip_address,
                ];
            });
    }

    /** @param list<string> $keys */
    private function detail(AuditLog $log, array $keys): ?string
    {
        $new = $this->values($log->new_values);
        $old = $this->values($log->old_values);
        foreach ($keys as $key) {
            $value = $new[$key] ?? $old[$key] ?? null;

            if ((is_string($value) || is_int($value)) && (string) $value !== '') {
                return Str::limit((string) $value, 160);
            }
        }

        return null;
    }

    /** @return list<string> */
    private function changedFields(AuditLog $log): array
    {
        return $log->event === 'updated'
            ? array_values(array_intersect_key(self::FIELD_LABELS, $this->values($log->new_values)))
            : [];
    }

    /** @return array<string, mixed> */
    private function values(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
