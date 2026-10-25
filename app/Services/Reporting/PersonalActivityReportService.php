<?php

namespace App\Services\Reporting;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

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

    /**
     * @return LengthAwarePaginator<int, array{
     *     id: int, event_label: string, entity_label: string, entity_id: int,
     *     description: string, created_at: string
     * }>
     */
    public function paginate(User $user, ?string $date = null): LengthAwarePaginator
    {
        return AuditLog::query()
            ->where('user_id', $user->id)
            ->when($date === 'today', fn (Builder $query) => $query->whereDate('created_at', today()))
            ->when($date === '7_days', fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(7)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15, ['id', 'event', 'auditable_type', 'auditable_id', 'description', 'created_at'])
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
                ];
            });
    }
}
