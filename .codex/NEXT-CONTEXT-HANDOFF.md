# Store Accounting System - Context Handoff

آخرین به‌روزرسانی: ۲۰۲۶-۱۰-۲۳ (پس از Phase 15A)

## شروع کانتکست جدید

قبل از هر تغییر، این فایل و سپس `.codex/retail-accounting-architecture.md` را
بخوان. مستندات فازی دیگر فقط برای جزئیات تاریخی و regressionهای قبلی‌اند.

مسیر پروژه:

```text
/home/omid/Projects/deepseek-agent/accounting/accounting-system
```

وضعیت Git:

- شاخه: `main`
- آخرین کامیت محلی: `349212c`
- پیام: `fix(inventory): harden cardex validation and role-aware UX`
- شاخه با `origin/main` هم‌سطح است.
- تغییرات Phase 15A در worktree آماده‌اند و commit آن‌ها بر عهده
  کاربر است.

## استک و محیط

- Laravel `13.38`
- PHP `8.3.6`
- Vue 3 + Inertia + Tailwind CSS 4
- Pest
- PHPStan/Larastan
- MySQL در محیط local
- تست‌های محلی Pest روی SQLite in-memory اجرا می‌شوند.
- workflow پروژه برای تست روی MySQL 8.4 تنظیم شده است.
- رابط کاربری فارسی، RTL و واکنش‌گراست.
- بررسی دستی مرورگر تا این لحظه انجام نشده است.

## وضعیت کیفیت فعلی

- Pest: `245 tests`, `1879 assertions`
- Node calendar: `3 tests`
- PHPStan: صفر خطا
- Pint: موفق
- Vue type-check: موفق
- Frontend build: موفق

دستور بررسی کامل:

```bash
composer ci:check
npm run build
```

## تصمیم‌های قطعی MVP

1. MVP تک‌فروشگاه است، اما `branch_id` از ابتدا در عملیات وجود دارد.
2. انبارها به Branch متصل‌اند.
3. ارزش‌گذاری موجودی: میانگین موزون متحرک.
4. موجودی منفی ممنوع است.
5. سامانه مودیان در MVP پیاده نمی‌شود.
6. پرداخت/دریافت ترکیبی و تخصیص چندفاکتوری پشتیبانی می‌شود.
7. مرجوعی خرید و فروش در Backend وجود دارد.
8. تأیید سند فقط برای `manager` و `accountant` است.
9. Draft قابل ویرایش/حذف است؛ سند نهایی فقط با سند اصلاحی تغییر می‌کند.
10. منطق مالی باید در Service باشد، نه Controller.

## نقش‌ها و دسترسی‌ها

- `manager`: مدیریت کامل، تأیید سند، خرید، دریافت، پرداخت و گزارش‌ها
- `accountant`: مشابه مدیر در تأیید و عملیات مالی
- `cashier`: ساخت پیش‌نویس فروش؛ اجازه تأیید نهایی یا ثبت دریافت ندارد
- `warehouse`: فعلاً به رابط‌های اصلی فروش/خرید دسترسی ندارد
- `viewer`: فقط دسترسی‌های محدود تعریف‌شده

## معماری دامنه

### حسابداری

- `PostingService`: تنها مسیر ثبت سند نهایی
- `JournalEntry` و `JournalEntryLine`: سند و ردیف‌های دوطرفه
- ثبت سند فقط در دوره باز و با کاربر مجاز
- سند Posted غیرقابل ویرایش و حذف
- کنترل توازن با bcmath
- `store_id`، `branch_id`، سال مالی و دوره در اسناد
- `parties` هویت واحد مشتری/تأمین‌کننده
- کد Party در هر فروشگاه یکتاست

### انبار

- منبع حقیقت: `inventory_movements`
- تصویر جاری: `inventory_balances`
- تمام تغییرات از `InventoryService`
- lockForUpdate روی موجودی
- idempotency حرکت انبار
- ممنوعیت حرکت Backdated پس از گردش بعدی
- COGS هنگام فروش Snapshot می‌شود

### خرید

- `PurchaseService`: Draft، ورود انبار، مالیات خرید، بدهی تأمین‌کننده و سند
- `PurchaseReturnService`: برگشت کنترل‌شده
- `SupplierPayableService`: محاسبه متمرکز مانده پرداختنی
- `SupplierPaymentService`: پرداخت چندفاکتوری و سند خزانه
- فاکتور خرید idempotency دارد.
- فاکتورهای پرداخت با ترتیب `id` قفل می‌شوند.
- تأمین‌کننده و Party هنگام Confirm/Payment قفل و مجدد کنترل می‌شوند.

### فروش

- `SalesService`: Draft، خروج انبار، COGS، درآمد، مالیات، مطالبات و سند
- `SalesReturnService`: برگشت فروش
- `CustomerReceivableService`: محاسبه متمرکز مانده دریافتنی
- `CustomerReceiptService`: دریافت چندفاکتوری و سند خزانه
- فاکتور فروش idempotency دارد.
- صندوق‌دار Draft می‌سازد؛ مدیر/حسابدار تأیید می‌کند.

### خزانه

- `TreasuryAccount` به حساب معین دفتر کل متصل است.
- Cash/Bank/Card در UI دریافت/پرداخت استفاده می‌شوند.
- چک دریافتی/پرداختی و lifecycle وصول/خرج/برگشت در Backend آماده است.
- UI اختصاصی چک و اصلاحات جریان اتصال آن پیاده‌سازی شده است.

### گزارش‌ها

- دفتر روزنامه
- دفتر معین
- تراز آزمایشی
- سود و زیان
- ترازنامه
- فقط اسناد Posted
- فیلتر فروشگاه، شعبه و بازه تاریخ

### سال مالی

- سال مالی با ۱۲ دوره شمسی، sequenceها و کنترل هم‌پوشانی ساخته می‌شود.
- ساخت سال آینده، سال جاری را عوض نمی‌کند.
- تعیین سال جاری فقط برای مدیر و فقط روی سال باز مجاز است.
- صفحه `/fiscal-years` سال‌ها و وضعیت دوره‌ها را نمایش می‌دهد.

## مسیرهای UI موجود

- `/dashboard`
- `/parties`
- `/products`
- `/sales`
- `/receipts`
- `/checks`
- `/treasury-accounts`
- `/users`
- `/audit-logs`
- `/purchases`
- `/payments`
- `/reports`
- `/inventory/cardex`
- `/fiscal-years`
- `/settings/profile`
- `/settings/security`

## فازهای تکمیل‌شده

- `phase-0-1`: معماری، Store/Branch/FiscalYear/Period/Sequence، Setting/Posting
- `inventory-slice`: Warehouse، InventoryMovement، InventoryBalance، InventoryService
- `accounting-hardening`: امنیت سند، double-entry و bcmath
- `phase-2a`: Parties، سال مالی شمسی، Purchase
- `phase-2b`: Purchase Return، Supplier Payment
- `phase-3a`: Sales، COGS، Customer Receipt، Sales Return
- `phase-4a`: Treasury Accounts و Check Lifecycle
- `phase-5a`: گزارش‌های مالی و UI
- `phase-6a`: مدیریت طرف‌حساب
- `phase-6b`: مدیریت کالا و موجودی
- `phase-7a`: رابط فروش/صندوق
- `phase-7b`: رابط دریافت مشتری
- `phase-7c`: رابط خرید و ورود انبار
- `phase-7d`: رابط پرداخت تأمین‌کننده
- `phase-8a`: رابط خزانه و مدیریت چک‌ها
- `phase-9a`: رابط برگشت خرید و فروش
- `phase-10a`: مدیریت حساب‌های خزانه
- `phase-11a`: Audit Log و مدیریت کاربران
- `phase-12a`: Dashboard تحلیلی واقعی
- `phase-13a`: Datepicker شمسی و نمودار ماهانه
- `phase-14a`: گزارش کاردکس تعدادی/ریالی و دسترسی انباردار
- `phase-15a`: مدیریت سال مالی، دوره‌ها و rollover

## فایل‌های کلیدی

### Backend

- `app/Services/Accounting/PostingService.php`
- `app/Services/Inventory/InventoryService.php`
- `app/Services/Purchasing/PurchaseService.php`
- `app/Services/Purchasing/PurchaseReturnService.php`
- `app/Services/Purchasing/SupplierPayableService.php`
- `app/Services/Purchasing/SupplierPaymentService.php`
- `app/Services/Sales/SalesService.php`
- `app/Services/Sales/SalesReturnService.php`
- `app/Services/Returns/ReturnableQuantityService.php`
- `app/Services/Sales/CustomerReceivableService.php`
- `app/Services/Sales/CustomerReceiptService.php`
- `app/Services/Treasury/CheckRegistrationService.php`
- `app/Services/Treasury/TreasuryAccountService.php`
- `app/Services/Users/UserManagementService.php`
- `app/Services/Audit/AuditLogService.php`
- `app/Services/Dashboard/DashboardService.php`
- `app/Services/Treasury/CheckSettlementService.php`
- `app/Services/Inventory/InventoryCardexReportService.php`
- `app/Services/Accounting/FiscalYearSetupService.php`

### Controllers

- `PartyController`
- `ProductController`
- `SalesInvoiceController`
- `CustomerReceiptController`
- `PurchaseInvoiceController`
- `SupplierPaymentController`
- `CheckController`
- `InvoiceReturnController`
- `TreasuryAccountController`
- `UserManagementController`
- `AuditLogController`
- `DashboardController`
- `FinancialReportsController`
- `InventoryCardexController`
- `FiscalYearController`

### UI

- `resources/js/pages/parties`
- `resources/js/pages/products`
- `resources/js/pages/sales`
- `resources/js/pages/receipts`
- `resources/js/pages/checks`
- `resources/js/pages/returns`
- `resources/js/pages/treasury-accounts`
- `resources/js/pages/users`
- `resources/js/pages/audit`
- `resources/js/pages/DashboardHome.vue`
- `resources/js/pages/purchases`
- `resources/js/pages/payments`
- `resources/js/pages/reports`
- `resources/js/pages/inventory/cardex`
- `resources/js/pages/fiscal-years`

## قواعد اجباری ادامه کار

- قبل از تغییر، schema و سرویس جاری همان دامنه را بخوان.
- برای مبلغ و مقدار از `Decimal` و bcmath استفاده کن.
- برای عملیات چندجدولی transaction و idempotency در نظر بگیر.
- در دریافت/پرداخت چندفاکتوری، فاکتورها را با ترتیب `id` قفل کن.
- برای عملیات هم‌زمان sensitive، `lockForUpdate` و version یا idempotency دارد.
- سند نهایی را حذف یا ویرایش مستقیم نکن.
- موجودی را خارج از `InventoryService` تغییر نده.
- Business logic را در Vue یا Controller تکرار نکن.
- Store/Branch scope را در Query و Service کنترل کن.
- ارقام فارسی/عربی و جداکننده‌های مالی باید نرمال شوند.
- برای enumها از Enumهای موجود استفاده کن.
- تست regression برای هر باگ واقعی اضافه کن.
- بدون درخواست صریح کاربر commit یا push نکن.

## محدودیت‌های شناخته‌شده

- محصولات هنوز `store_id` ندارند؛ کاتالوگ در MVP سراسری است.
- تاریخ‌های فرم‌های مالی از Datepicker شمسی استفاده می‌کنند؛ ورود تایپی مستقیم
  و بررسی دستی تقویم در مرورگر باقی مانده است.
- UI برگشت خرید و فروش اضافه شده است؛ ویرایش Draft مرجوعی هنوز وجود ندارد.
- مدیریت حساب‌های صندوق، بانک و کارت از UI اضافه شده است؛ مدیریت چندشعبه
  همچنان خارج از MVP است.
- هزینه‌های جانبی خرید و حمل پیاده‌سازی نشده‌اند.
- دسته‌بندی، تنوع کالا، سریال، Batch، تاریخ انقضا و PriceHistory وجود ندارند.
- موجودی‌گیری فیزیکی و StockAdjustment اختصاصی وجود ندارند.
- کاردکس موجودی پیاده‌سازی شده است؛ فیلتر کالای آن فعلاً select ساده است و
  برای کاتالوگ بسیار بزرگ به combobox جست‌وجوشونده نیاز دارد.
- خروجی PDF/Excel گزارش کاردکس هنوز وجود ندارد.
- Audit Log و مدیریت کاربران/نقش‌ها وجود دارد؛ غیرفعال‌سازی کاربر، retention و
  alerting برای Audit هنوز پیاده‌سازی نشده‌اند.
- سند دستی، مانده افتتاحیه، بستن دوره و اسناد اختتامیه/افتتاحیه هنوز وجود
  ندارند.
- Dashboard برای Manager/Accountant داده واقعی و نمودار هفت‌روزه/شش‌ماهه دارد؛
  cache و پاک‌سازی فایل Dashboard قدیمی باقی مانده است.
- سامانه مودیان خارج از MVP است.
- بررسی دستی مرورگر و acceptance نهایی انجام نشده است.

## گام بعدی پیشنهادی

1. بررسی دستی و commit تغییرات Phase 15A توسط کاربر
2. Phase 15B: سند دستی و مانده افتتاحیه
3. Phase 15C: بستن و بازگشایی دوره
4. ادامه طبق `.codex/phase-15-roadmap.md`

جزئیات Phase 8A در `.codex/phase-8a-treasury-checks-ui.md` ثبت شده است.
اصلاحات بازبینی آن در `.codex/phase-8a-check-hardening.md` آمده است.
جزئیات Phase 9A در `.codex/phase-9a-return-ui.md` آمده است.
جزئیات Phase 10A در `.codex/phase-10a-treasury-account-ui.md` آمده است.
جزئیات Phase 11A در `.codex/phase-11a-audit-users.md` آمده است.
جزئیات Phase 12A در `.codex/phase-12a-dashboard-analytics.md` آمده است.
جزئیات Phase 13A در `.codex/phase-13a-jalali-datepicker-monthly-chart.md` آمده است.
جزئیات Phase 14A در `.codex/phase-14a-inventory-cardex.md` آمده است.
جزئیات Phase 15A در `.codex/phase-15a-fiscal-year-management.md` آمده است.
نقشه مراحل تکمیل در `.codex/phase-15-roadmap.md` آمده است.

## الگوی درخواست از Agent بعدی

پاسخ مناسب برای شروع کانتکست جدید:

```text
در پروژه accounting-system ادامه بده.
ابتدا .codex/NEXT-CONTEXT-HANDOFF.md و
.codex/retail-accounting-architecture.md را کامل بخوان.
سپس وضعیت Git و CI را بررسی کن و مرحله بعد را طبق handoff شروع کن.
Business logic نباید وارد Controller شود و commit را خودم می‌زنم.
```
