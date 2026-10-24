# Store Accounting System - Context Handoff

آخرین به‌روزرسانی: ۲۰۲۶-۱۰-۲۴ (پس از Phase 15I)

## شروع کانتکست جدید

قبل از هر تغییر، این فایل و سپس `.codex/retail-accounting-architecture.md` را
بخوان. مستندات فازی دیگر فقط برای جزئیات تاریخی و regressionهای قبلی‌اند.

مسیر پروژه:

```text
/home/omid/Projects/deepseek-agent/accounting/accounting-system
```

وضعیت Git:

- شاخه: `main`
- آخرین کامیت محلی و remote: `0acf461`
- پیام: `feat(branches): add employees and branch-aware operations`
- CI همین commit در GitHub موفق است.
- تغییرات Single-location MVP در worktree آماده commit هستند.
- migrationها تا `2026_10_24_000004_add_branch_assignment_to_users` در دیتابیس
  محلی اجرا شده‌اند.
- commit تغییرات بر عهده
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

- Pest: `303 tests`, `2416 assertions`
- Node calendar: `4 tests`
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

1. سطح محصول و UI فعلاً تک‌فروشگاهی است؛ تعریف، انتخاب و تغییر شعبه در محصول
   وجود ندارد.
2. `Branch` و `branch_id` فقط به‌عنوان سازگاری فنی و آماده‌سازی نسخه بعدی
   نگه داشته می‌شوند. هر استقرار دقیقاً یک شعبهٔ پیش‌فرض `MAIN` دارد.
3. انبارها از نظر دیتابیس به همان Branch فنی متصل‌اند.
4. ارزش‌گذاری موجودی: میانگین موزون متحرک.
5. موجودی منفی ممنوع است.
6. سامانه مودیان در MVP پیاده نمی‌شود.
7. پرداخت/دریافت ترکیبی و تخصیص چندفاکتوری پشتیبانی می‌شود.
8. مرجوعی خرید و فروش در Backend وجود دارد.
9. تأیید سند فقط برای `manager` و `accountant` است.
10. Draft قابل ویرایش/حذف است؛ سند نهایی فقط با سند اصلاحی تغییر می‌کند.
11. منطق مالی باید در Service باشد، نه Controller.

## محدوده Single-location MVP

- منوی شعبه، انتخاب شعبه در هدر، فیلتر شعبه در گزارش‌ها و انتخاب شعبه در
  تنظیمات چاپ از UI حذف شده‌اند.
- همهٔ سرویس‌ها مستقل از role، session قدیمی یا `users.branch_id` فقط شعبهٔ
  پیش‌فرض را انتخاب می‌کنند.
- کارمندان جدید در سطح فروشگاه تعریف می‌شوند و تخصیص شعبه ندارند.
- routeهای مدیریت/تغییر شعبه با middleware
  `RejectSingleLocationDisabledRoutes` همیشه 404 می‌شوند.
- ستون‌ها، مدل‌ها و سرویس‌های Branch حذف نشدند تا داده و مسیر نسخه بعدی حفظ
  شود.

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
- گزارش‌ها روی شعبهٔ پیش‌فرض فروشگاه و بازه تاریخ اجرا می‌شوند.
- دفتر معین با مانده اول/پایان و گردش حساب
- صورتحساب مشتری/تأمین‌کننده از ردیف‌های Party
- Aging مطالبات/بدهی‌ها تا تاریخ گزارش
- فروش روزانه، فروش کالا، COGS، سود ناخالص و حاشیه سود
- خروجی CSV سازگار با Excel برای گزارش‌های مالی، عملیاتی، کاردکس و Audit
- چاپ A4 Landscape گزارش‌ها و چاپ حرارتی ۸۰ میلی‌متری فاکتور فروش
- خروجی کاردکس تمام فیلترهای فعال همان صفحه، شامل نوع گردش و جهت، را حفظ می‌کند.
- CSV جمع‌ها و مانده‌های کلیدی را دارد و سود دارای COGS ناقص را خالی می‌گذارد.
- سربرگ چاپ زمینه گزارش و صفحه‌بندی را نشان می‌دهد و فاکتور هویت فروشنده را دارد.
- تنظیمات چاپ در سطح فروشگاه، قالب پیش‌فرض حرارتی ۵۸/۸۰، A5/A4، متن سربرگ و
  یک تا پنج نسخه دارد.
- هنگام چاپ، قالب و نسخه برای همان چاپ قابل تغییر است و تکرار نسخه‌ها در نمای
  مستقل فاکتور اعمال می‌شود.
- بازه یک‌طرفه گزارش‌ها اعتبارسنجی می‌شود و خطای 500 نمی‌دهد.
- حساب غیرفعال برای گزارش تاریخی قابل انتخاب است.
- ردیف فروش بدون COGS snapshot، سود را «نامشخص» نشان می‌دهد.

### سال مالی

- سال مالی با ۱۲ دوره شمسی، sequenceها و کنترل هم‌پوشانی ساخته می‌شود.
- ساخت سال آینده، سال جاری را عوض نمی‌کند.
- تعیین سال جاری فقط برای مدیر و فقط روی سال باز مجاز است.
- صفحه `/fiscal-years` سال‌ها و وضعیت دوره‌ها را نمایش می‌دهد.

### اسناد دستی

- سند دستی چندردیفی با پیش‌نویس، ویرایش، حذف و تأیید وجود دارد.
- مانده افتتاحیه دارایی، بدهی و سرمایه یک‌بار در ابتدای سال ثبت می‌شود.
- نوع سند `entry_kind` و ثبت نهایی از `PostingService` کنترل می‌شود.
- حساب موجودی از سند دستی مسدود است تا از دفتر حرکت انبار جدا نشود.
- ثبت همه اسناد، قفل هم‌ترتیب سال و دوره را رعایت می‌کند.

### بستن دوره و سال

- دوره می‌تواند نیمه‌بسته یا بسته نهایی شود.
- سند، فاکتور، دریافت و پرداخت باز مانع بستن هستند.
- Draft فروش، خرید و مرجوعی فقط در دوره باز ساخته می‌شود.
- ساخت Draft و بستن دوره قفل هم‌ترتیب سال/دوره دارند.
- بازگشایی دوره فقط برای مدیر و با دلیل Audit انجام می‌شود.
- سال مالی پس از بستن نهایی همه دوره‌ها بسته می‌شود.
- بستن سال جاری نیازمند سال بعد باز است و سال جاری را خودکار منتقل
  می‌کند.
- بازگشایی سال، دوره‌ها را خودکار باز نمی‌کند.
- بستن سال فقط بعد از پایان بازه سال و با ۱۲ دوره پیوسته مجاز است.
- فیلدهای وضعیت و بستن از تغییر مستقیم مدل محافظت می‌شوند.

### اختتامیه سالانه

- بستن سال سند اختتامیه هر شعبه دارای گردش را ایجاد می‌کند.
- حساب‌های درآمد و هزینه صفر و سود/زیان خالص به حساب `3102` منتقل می‌شود.
- سند اختتامیه از `PostingService` ثبت می‌شود.
- بازگشایی سال، سند اختتامیه فعال را با سند معکوس بی‌اثر می‌کند.
- گزارش سود و زیان، سند اختتامیه و معکوس آن را از محاسبه حذف می‌کند.
- مانده ترازنامه با دفتر پیوسته منتقل می‌شود و سند افتتاحیه تکراری ساخته
  نمی‌شود.
- مانده افتتاحیه دستی فقط برای اولین سال مالی مجاز است.
- بستن سال با Opening فعال سال بعد متوقف می‌شود.
- سند معکوس تاریخی حساب/طرف‌حساب غیرفعال سند اصلی را می‌پذیرد.

### موجودی عملیاتی

- شمارش چندکالایی با Draft، Edit، Post و اتصال به حرکت انبار وجود دارد.
- شمارش نهایی موجودی سیستم را مجدداً می‌خواند و سند مازاد/کسری می‌سازد.
- انتقال چندکالایی بین دو انبار یک شعبه با دو movement متناظر برای هر کالا
  در یک transaction انجام می‌شود.
- انباردار شمارش خودش و انتقال را انجام می‌دهد؛ ثبت تعدیل فقط مدیر/حسابدار
  است.
- اطلاعات بهای خرید و ارزش تعدیل/انتقال برای انباردار حذف می‌شود.

## مسیرهای UI موجود

- `/dashboard`
- `/parties`
- `/products`
- `/product-categories`
- `/products/{product}/tracking`
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
- `/journals`
- `/inventory/counts`
- `/inventory/transfers`
- `/reports/operations`
- `/reports/export/financial`
- `/reports/export/operations`
- `/inventory/cardex/export`
- `/audit-logs/export`
- `/settings/print`
- `/branches` فنی است و در Single-location MVP پاسخ 404 می‌دهد.
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
- `phase-15b`: سند دستی و مانده افتتاحیه
- `phase-15c`: قفل عملیاتی دوره و سال
- `phase-15c2`: اختتامیه سالانه و انتقال سود
- `phase-15d`: شمارش، تعدیل و انتقال انبار
- `phase-15e`: دفتر معین، صورتحساب، Aging و گزارش‌های فروش
- `phase-15f`: خروجی CSV، چاپ گزارش و چاپ حرارتی فاکتور
- `phase-15f2`: تنظیمات چاپ هر شعبه و قرارداد Print Agent
- `phase-15g1`: تک‌مستاجری، مدیریت شعبه و تخصیص کارمند
- `phase-15g1s`: غیرفعال‌سازی سطح محصول چندشعبه‌ای و الزام شعبهٔ پیش‌فرض
- `phase-15h`: تکمیل قابلیت‌های عملیاتی و مقیاس‌پذیری
- `phase-15i`: دسته‌بندی، تنوع، ردیابی و سیاست موجودی کالا

## تکمیل قابلیت‌های عملیاتی

- ویرایش Draft مرجوعی فروش و خرید با قفل فاکتور اصلی و کنترل مجدد سقف
- انتقال چندکالایی اتمیک با snapshot هزینهٔ هر قلم
- ورود CSV برای شمارش موجودی با اعتبارسنجی کد/بارکد، مقدار و بهای واحد
- جست‌وجوی سروری محصول با نام، کد یا بارکد در شمارش، انتقال و کاردکس
- ورود تایپی تاریخ شمسی در تمام JalaliDatePickerها
- ردیف‌های هزینهٔ جانبی خرید و تخصیص آن به بهای موجودی اقلام
- pagination و cache کوتاه‌مدت گزارش‌های صورتحساب، Aging و فروش
- cache یک‌دقیقه‌ای Dashboard در محیط غیر Testing
- حذف بارگذاری کل کاتالوگ در انتخاب‌گرهای عملیاتی

## تکمیل کالا و انبار

- دسته‌بندی کالا با فیلتر کاتالوگ و صفحه مدیریت مستقل
- کالای والد و تنوع رنگ/اندازه به‌صورت SKU مستقل با موجودی و بارکد جداگانه
- حذف کالای والد از lookup فروش، خرید، انتقال و شمارش
- شماره سریال با وضعیت، انبار، Batch و ابطال کنترل‌شده
- Batch و تاریخ تولید/انقضا با هشدار انقضای نزدیک و منقضی
- تاریخچهٔ قیمت خرید و فروش با کاربر و زمان تغییر
- نقطه سفارش، موجودی ایمنی، مقدار سفارش، حداکثر موجودی و سیاست Min/Max
- پیشنهاد مقدار سفارش در کاتالوگ و Dashboard

## فایل‌های کلیدی

### Backend

- `app/Services/Accounting/PostingService.php`
- `app/Services/Accounting/PeriodClosingService.php`
- `app/Services/Accounting/YearEndClosingService.php`
- `app/Services/Inventory/StockCountService.php`
- `app/Services/Inventory/InventoryTransferService.php`
- `app/Services/Reporting/PartyStatementReportService.php`
- `app/Services/Reporting/AgingReportService.php`
- `app/Services/Reporting/SalesOperationsReportService.php`
- `app/Services/Reporting/ReportExportService.php`
- `app/Services/Reporting/CsvExportService.php`
- `app/Services/Reporting/AuditLogReportService.php`
- `app/Services/Printing/PrintSettingsService.php`
- `app/Services/Branches/BranchContextService.php`
- `app/Services/Branches/BranchManagementService.php`
- `app/Enums/InvoicePrintFormat.php`
- `app/Services/Accounting/AccountingPeriodLockService.php`
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
- `app/Services/Accounting/ManualJournalService.php`
- `app/Services/Accounting/PostingService.php`

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
- `ManualJournalController`
- `StockCountController`
- `InventoryTransferController`
- `OperationalReportsController`
- `ReportExportController`
- `Settings\PrintSettingsController`
- `BranchManagementController`
- `ActiveBranchController`

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
- `resources/js/pages/journals`
- `resources/js/pages/inventory/counts`
- `resources/js/pages/inventory/transfers`
- `resources/js/pages/reports/Operations.vue`
- `resources/js/pages/audit/Index.vue`
- `resources/js/pages/sales/Show.vue`
- `resources/js/pages/sales/SalesReceipt.vue`
- `resources/js/pages/sales/SalesInvoicePage.vue`
- `resources/js/pages/settings/Print.vue`
- `resources/js/pages/branches/Index.vue`
- `resources/js/pages/branches/Form.vue`

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
- تاریخ‌های فرم‌های مالی از Datepicker شمسی و ورود تایپی پشتیبانی می‌کنند؛
  بررسی دستی تقویم در مرورگر باقی مانده است.
- UI برگشت خرید و فروش و ویرایش Draft مرجوعی پیاده‌سازی شده است.
- مدیریت حساب‌های صندوق، بانک و کارت از UI اضافه شده است؛ مدیریت شعبه برای
  نسخه بعدی تعویق افتاده و routeهای آن 404 هستند.
- هزینه‌های جانبی خرید و حمل پیاده‌سازی شده‌اند و هنگام تأیید به موجودی
  تخصیص می‌یابند.
- Batch و سریال شناسه‌های ردیابی کالا هستند؛ موجودی مالی همچنان در سطح SKU و
  انبار با میانگین موزون کنترل می‌شود.
- انتقال چندکالایی و فقط داخل شعبهٔ فنی پیش‌فرض است.
- شمارش، جست‌وجوی سروری کالا و ورود CSV موجودی دارد.
- صورتحساب، Aging و فروش pagination و cache کوتاه‌مدت دارند.
- خروجی Excel در قالب CSV سازگار با Excel است، نه فایل بومی `.xlsx`.
- خروجی PDF از Print to PDF مرورگر است، نه فایل PDF سمت سرور.
- کاردکس موجودی و انتخاب کالای آن از lookup جست‌وجوشونده و صفحه‌بندی‌شده
  استفاده می‌کند.
- بررسی دستی قالب‌های A4/A5 و چاپگرهای حرارتی ۵۸/۸۰ انجام نشده است.
- چاپ بی‌صدا و Print Agent پیاده نشده‌اند؛ فقط قرارداد امنیتی و معماری آن‌ها
  در `.codex/print-agent-architecture.md` آماده شده است.
- کارمندان تخصیص شعبه ندارند؛ تخصیص کارمند و عملیات چندشعبه‌ای برای نسخه بعدی
  تعویق افتاده است.
- First-run setup برای ساخت امن مدیر اولیه، فروشگاه و دوره مالی هنوز پیاده
  نشده است.
- Audit Log و مدیریت کاربران/نقش‌ها وجود دارد؛ غیرفعال‌سازی کاربر، retention و
  alerting برای Audit هنوز پیاده‌سازی نشده‌اند.
- سند افتتاحیه تکراری وجود ندارد؛ انتقال مانده ترازنامه با دفتر پیوسته انجام
  می‌شود.
- موجودی افتتاحیه کالا با شمارش و تعدیل کنترل‌شده ثبت می‌شود؛ سند افتتاحیه
  ریالی تکراری ساخته نمی‌شود.
- Dashboard برای Manager/Accountant داده واقعی و نمودار هفت‌روزه/شش‌ماهه دارد
  و خروجی آن یک دقیقه cache می‌شود؛ حذف فایل قدیمی `Dashboard.vue` همچنان
  پاک‌سازی اختیاری است.
- سامانه مودیان خارج از MVP است.
- بررسی دستی مرورگر و acceptance نهایی انجام نشده است.

## گام بعدی پیشنهادی

1. بررسی دستی UI تک‌فروشگاهی و commit تغییرات Single-location MVP توسط کاربر
2. First-run setup و ساخت امن مدیر اولیه
3. غیرفعال‌سازی کاربر و reset کنترل‌شده 2FA
4. backup/restore، retention Audit و چک‌لیست deployment
5. پاک‌سازی فایل قدیمی Dashboard و بررسی بار واقعی queryهای صفحه‌بندی‌شده
   در داده بزرگ.

پیش از فعال‌کردن مجدد چندشعبه‌ای، این سه ایراد باید رفع و تست شوند:

- ساخت `DocumentSequence` برای شعبه و سال مالی جدید
- محدودکردن query لیست شمارش و انتقال به شعبهٔ فعال
- انجام اتمیک کنترل «آخرین شعبهٔ فعال» هنگام تغییر وضعیت

جزئیات Phase 8A در `.codex/phase-8a-treasury-checks-ui.md` ثبت شده است.
اصلاحات بازبینی آن در `.codex/phase-8a-check-hardening.md` آمده است.
جزئیات Phase 9A در `.codex/phase-9a-return-ui.md` آمده است.
جزئیات Phase 10A در `.codex/phase-10a-treasury-account-ui.md` آمده است.
جزئیات Phase 11A در `.codex/phase-11a-audit-users.md` آمده است.
جزئیات Phase 12A در `.codex/phase-12a-dashboard-analytics.md` آمده است.
جزئیات Phase 13A در `.codex/phase-13a-jalali-datepicker-monthly-chart.md` آمده است.
جزئیات Phase 14A در `.codex/phase-14a-inventory-cardex.md` آمده است.
جزئیات Phase 15A در `.codex/phase-15a-fiscal-year-management.md` آمده است.
جزئیات Phase 15B در `.codex/phase-15b-manual-journals-opening-balance.md` آمده است.
جزئیات Phase 15C در `.codex/phase-15c-period-year-closing.md` آمده است.
جزئیات Phase 15C2 در `.codex/phase-15c2-year-end-closing.md` آمده است.
جزئیات Phase 15D در `.codex/phase-15d-stock-count-transfer.md` آمده است.
جزئیات Phase 15E در `.codex/phase-15e-operational-reports.md` آمده است.
جزئیات Phase 15F در `.codex/phase-15f-report-exports-print.md` آمده است.
قرارداد Print Agent در `.codex/print-agent-architecture.md` آمده است.
جزئیات Phase 15G1 در `.codex/phase-15g1-single-tenant-branches-employees.md`
آمده است.
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
