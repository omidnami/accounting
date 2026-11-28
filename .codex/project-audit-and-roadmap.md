# گزارش ممیزی عمیق پروژه و اولویت کارها

آخرین به‌روزرسانی: ۲۰۲۶-۱۱-۲۸

کامیت مبنا: `28a8c28` — `docs: define base accounting scope and gaps`

## هدف سند

این گزارش پروژهٔ فعلی را با اسناد زیر مقایسه می‌کند:

- `.codex/PROJECT-CONTEXT.md`
- `.codex/retail-accounting-architecture.md`
- `.codex/accounting-coding-research.md`
- `.codex/retail-accounting-product-research.md`
- `.codex/platform-and-multi-industry-architecture.md`

ترتیب اولویت سند عمداً این است:

1. رفع ایرادهای تأییدشده و ریسک‌های بحرانی
2. تکمیل امکانات پایهٔ کم‌موجود
3. آماده‌سازی راست‌چین و معماری ماژولار
4. افزودن قابلیت‌های اختیاری صنفی

این سند مجوز پیاده‌سازی نیست؛ فقط فهرست اولویت‌بندی‌شده است.

## وضعیت اجرای اصلاحات — ۲۰۲۶-۱۱-۲۸

اجرای درخواست‌شده برای بخش ۱ در کامیت‌های `3ce7364` تا `c0df773` و ادامه‌های
B19/B46 و بستن کامل P2 انجام شد. علائم: ✅ اصلاح‌شده، 🟡 پایه‌گذاری/اصلاح جزئی، ⬜ باقی‌مانده.

### P1

- ✅ A01–A16، A19–A31 از نظر مسیرهای اصلی اصلاح یا پیاده‌سازی شدند.
- 🟡 A17: resolver و ایزolation DB/cache/session/file/log اضافه شد؛ provisioning و
  آزمون production راست‌چین هنوز کامل نیست.
- 🟡 A18: قرارداد عمومی posting و catalog/registry/lifecycle ماژول اضافه شد؛
  boot کامل providerها و UI مدیریت ماژول هنوز باقی است.
- 🟡 A19: preflight، README و runbook اضافه شد؛ drill واقعی استقرار/rollback انجام
  نشده است.

### P2

- ✅ B01–B46 کامل شدند.
- ✅ B02، B06، B09، B23، B27، B30، B33، B38 و B39 نیز در این مرحله بسته شدند:
  retry/lock order، اصلاح تاریخی، یکتایی شناسهٔ کالا، lifecycle چک و Print Station،
  قرارداد مستقیم سرویس‌ها، canonical idempotency، snapshot هویت طرف‌حساب و
  invariant دقیق allocationهای Batch/Serial.
- ✅ باگهای گزارش‌شدهٔ کاربر نیز رفع شدند: واژه‌گذاری رنگ/سایز، لینک ویرایش
  کالا، پیام صریح نبود موجودی، ویرایش پیش‌نویس فروش/خرید و یکسان‌سازی snapshot
  مشتری در POS و فروش عادی با backfill امن از Audit.

### شواهد اجرایی جدید

- `php artisan test --exclude-testsuite=Integration`: SQLite با ۶۱۱ تست موفق و
  ۵۴۲۲ assertion؛ MariaDB 10.11 با ۶۱۰ تست موفق، ۵۴۱۵ assertion و یک skip
  مربوط به trigger مخصوص SQLite.
- `tests/Integration/ConcurrencySafetyTest.php --fail-on-skipped`: ۹ تست موفق و
  ۳۵ assertion، شامل سناریوی جدید مرجوعی هم‌زمان با ترتیب اقلام مخالف.
- B30: هر ۷۲ سرویس `app/Services` دست‌کم یک ارجاع مستقیم در تست‌ها دارند.
- Pint، PHPStan، TypeScript، `npm run check` و build موفق‌اند.
- `composer audit --locked` و `npm audit --omit=dev`: بدون advisory.
- `composer ci:check` سبز است.
- migration کامل روی MariaDB 10.11 محلی موفق بود.
- CI نهایی روی MySQL 8.4 و MariaDB 11.4 برای `4d7c92a`:
  [Run 46133769536](https://github.com/omidnami/accounting/actions/runs/46133769536).
  هر دو job، migrations، `composer ci:check` و ۹ تست هم‌زمانی را موفق اجرا کردند.
- backup/restore واقعی دیتابیس روی MariaDB با marker داده موفق بود.

---

## خلاصهٔ اجرایی

- **هیچ ایراد P0 اثبات‌شده در بازبینی استاتیک پیدا نشد.**
- هستهٔ حسابداری، انبار، COGS، مرجوعی، تخصیص دریافت/پرداخت و POS از نظر ساختاری
  قوی است و تست‌های زیادی دارد.
- همهٔ اصلاحات P2 بخش ۱ (B01–B46) بسته شدند.
- نسخهٔ فعلی برای production راست‌چین و انتشار نهایی تأیید نشده است؛ کارهای
  باقی‌مانده عبارت‌اند از:
    - A17: provisioning و آزمون production ایزolation واقعی tenant
    - A18: boot کامل providerها و UI مدیریت lifecycle ماژول
    - A19: drill واقعی استقرار/rollback و monitoring production
    - پذیرش دستی Chrome/Firefox و چاپگر واقعی
- وابستگی‌های آسیب‌پذیر، فرمت CI، تاریخ کسب‌وکار، VAT، rounding POS، تخصیص
  مرجوعی، refund، First-run، backup/restore، کالای غیرانباری و lifecycle کاربر
  در مراحل قبلی اصلاح شده‌اند.

---

## روش و محدودهای بررسی

### دامنهٔ بررسی

- ۵۰ مدل
- ۷۲ سرویس
- ۴۱ Controller
- ۷۵ migration
- ۴ Seeder
- ۹۷ فایل تست
- ۶۶ صفحهٔ Vue

بررسی شامل سیدرها، کدینگ حساب، schema، migration، posting، دوره و سال مالی،
کالا، انبار، خرید، فروش، مرجوعی، خزانه، چک، POS، چاپ، گزارش، امنیت، کاربران،
Audit، CI و آمادگی چندمستأجری بود.

### شواهد اجرایی

| بررسی                                              | نتیجه                                                   |
| -------------------------------------------------- | ------------------------------------------------------- |
| `php artisan test --exclude-testsuite=Integration` | ۳۵۰ تست موفق، ۲۹۴۳ assertion                            |
| Pint                                               | موفق                                                    |
| PHPStan                                            | موفق، صفر خطا                                           |
| `npm run types:check`                              | موفق                                                    |
| تست تقویم                                          | ۴ تست موفق                                              |
| تست POS                                            | ۱۰ تست موفق؛ یکی truncation نادرست را انتظار دارد       |
| `npm run build`                                    | موفق                                                    |
| `composer ci:check`                                | ناموفق در `npm run check` به‌دلیل فرمت ۴ فایل Markdown  |
| `composer audit --locked`                          | ۱۸ advisory روی ۸ پکیج                                  |
| `npm audit --omit=dev`                             | صفر آسیب‌پذیری runtime                                  |
| `npm audit` کامل                                   | ۱۰ آسیب‌پذیری dev؛ ۳ مورد critical                      |
| تست هم‌زمانی MySQL محلی                            | اجرا نشد؛ دسترسی به دیتابیس تست MySQL در محیط محلی نبود |

### محدودیت‌ها

- مرورگر، چاپگر فیزیکی، Firefox، دستگاه لمسی و production آزمایش نشدند.
- تست هم‌زمانی MySQL/MariaDB در این محیط اجرا نشد.
- یافته‌های «ریسک» نیازمند آزمون runtime یا concurrency هستند.
- audit وابستگی‌ها به تاریخ همین گزارش مربوط است.

---

## تعریف اولویت

| اولویت | معنا                                                                    |
| ------ | ----------------------------------------------------------------------- |
| P0     | خرابی داده، سوءاستفادهٔ امنیتی یا گزارش مالی کاملاً نادرست؛ توقف انتشار |
| P1     | مانع انتشار، باگ تأییدشده یا blocker راست‌چین/ماژولار                   |
| P2     | ایراد مهم، ناقصی یا ریسک نیازمند اصلاح برنامه‌ریزی‌شده                  |
| Gap    | قابلیت توافق‌شده که هنوز وجود ندارد                                     |

---

# بخش ۱ — ایرادهای اصلاحی

## ۱.۱ اولویت P1

| ID  | ایراد                                                                        | شاهد                                                                                                                                                                                                | اثر                                                                                       | اصلاح پیشنهادی / معیار پذیرش                                                                                                  |
| --- | ---------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| A01 | وابستگی‌های آسیب‌پذیر                                                        | `composer.lock`: Laravel `13.38.0`، Symfony `7.4.21/7.4.19`، WebAuthn `5.3.9`؛ `composer audit` ۱۸ advisory                                                                                         | ریسک امنیتی؛ یکی از advisoryها medium است                                                 | ارتقای Laravel به `13.44.1`، Symfony به `7.4.23+` و WebAuthn به `5.3.13+`؛ CI باید `composer audit` و `npm audit` را اجرا کند |
| A02 | شکست CI به‌دلیل فرمت مستندات                                                 | `composer ci:check` در `vp check` روی ۴ فایل `.codex` خطا می‌دهد                                                                                                                                    | هیچ release یا MRی سبز نمی‌شود                                                            | فرمت مستندات یا استثنای `.codex` از fmt؛ `composer ci:check` باید کامل اجرا شود                                               |
| A03 | تاریخ مالی با timezone کسب‌وکار همخوان ندارد                                 | `config/app.php:68` روی UTC؛ `StoreSeeder.php:21` روی Tehran؛ `PosCheckoutService.php:56` از `today()` UTC استفاده می‌کند                                                                           | فروش و گزارش روزانه بین ۰۰:۰۰ تا ۰۳:۳۰ تهران در روز قبل ثبت می‌شوند                       | Business Clock مبتنی بر `Store.timezone`؛ تست مرز نیمه‌شب و سال مالی                                                          |
| A04 | مبلغ POS مرورگر و سرور متفاوت است                                            | `resources/js/lib/pos.ts:115-130` truncate می‌کند؛ `app/Support/Decimal.php:86-95` half-up می‌کند؛ `PosCheckoutService.php:118` تساوی دقیق می‌خواهد                                                 | فروش معتبر با مبلغ نمایش‌داده‌شده قابل پرداخت نیست؛ fixture: سرور `17.98`، مرورگر `17.96` | قرارداد rounding مشترک؛ تست fixture مشترک PHP/Node و مرز half-cent                                                            |
| A05 | داشبورد دادهٔ انبار را به صندوقدار و Viewer نشت می‌دهد                       | `DashboardService.php:103` پیش از بررسی نقش `expiryAlerts` را می‌سازد؛ `UserRole.php:33-40` دسترسی انبار را می‌بندد                                                                                 | افشای نام کالا، Batch و انقضا به نقش غیرمجاز                                              | `expiry_alerts` فقط برای `canViewInventoryReports()`؛ تست پاسخ خالی نقش‌های دیگر                                              |
| A06 | تغییر رمز، sessionهای قبلی را باطل نمی‌کند                                   | `SecurityController.php:56-60`، `ResetUserPassword.php:19-27`، `UserManagementService.php:80-84`                                                                                                    | مهاجم پس از reset رمز همچنان وارد می‌ماند                                                 | rotate `remember_token` و حذف sessionهای دیگر؛ تست دو نشست                                                                    |
| A07 | Print Agent توکن را روی HTTP می‌فرستد                                        | `RunPrintAgent.php:23-36` هیچ اعتبارسنجی HTTPS ندارد                                                                                                                                                | سرقت bearer token و payload فاکتور                                                        | اجبار HTTPS به‌جز localhost توسعه؛ منع redirect downgrade؛ تست ارسال نشدن توکن روی HTTP                                       |
| A08 | Print Agent پس از قطعی شبکه یا crash متوقف می‌شود و job claimed رها می‌شود   | `RunPrintAgent.php:32-79`؛ `PrintAgentController.php:18-30` فقط job queued منقضی را fail می‌کند                                                                                                     | توقف چاپ و jobهای ابدی claimed                                                            | retry/backoff، lease/heartbeat و reclaim job claimed منقضی؛ completion idempotent                                             |
| A09 | نصب تازه مسیر First-run ندارد                                                | `composer.json:47-54` seed نمی‌کند؛ `DatabaseSeeder.php:14-17` کاربر نمی‌سازد؛ registration عمومی 404 است                                                                                           | نصب production بدون SQL/Tinker غیرممکن است                                                | setup یک‌بارهٔ امن و transactional برای مدیر اول، Store، Branch، انبار، سال و حساب‌ها                                         |
| A10 | Backup/restore وجود ندارد                                                    | `routes/console.php:11` فقط expiry alert دارد؛ هیچ command/job/test backup پیدا نشد                                                                                                                 | مسیر بازیابی دادهٔ مالی اثبات نشده است                                                    | backup رمزنگاری‌شدهٔ DB و فایل، retention، monitoring و restore آزموده‌شده                                                    |
| A11 | غیرفعال‌سازی کاربر و reset مدیریتی 2FA وجود ندارد                            | `User.php:37-69` وضعیت فعال ندارد؛ `UserManagementService.php:19-91` فقط create/update است                                                                                                          | کارمند مخلوع یا از دست‌رفته همچنان دسترسی دارد                                            | deactivation با ابطال فوری session/token، سیاست شیفت POS باز و reset 2FA دارای Audit                                          |
| A12 | رویدادهای امنیتی Audit نمی‌شوند                                              | `FortifyServiceProvider.php:30-35` فقط action/view/rate limit؛ `config/audit.php:25-46` فقط model observer                                                                                          | login، reset، 2FA و passkey قابل ردیابی نیستند                                            | listener برای رویدادهای امنیتی؛ ثبت IP/UA/نتیجه بدون secret                                                                   |
| A13 | کالای غیرانباری و خدمت پشتیبانی نمی‌شوند                                     | `ProductStockPolicy.php:5-8` فقط reorder policy؛ `SalesService.php:253-271` و `PurchaseService.php:335-390` همیشه انبار می‌سازند                                                                    | کسب‌وکار خدماتی نمی‌تواند از نسخهٔ پایه استفاده کند                                       | `item_type` یا سیاست non-stock؛ فروش/خرید خدمت بدون movement و COGS                                                           |
| A14 | سریال‌های متفاوت فقط در whitespace می‌توانند موجودی را خراب کنند             | `PurchaseInvoiceRequest.php:64` distinct قبل trim؛ `InventoryService.php:508-558` بعد از trim ID تکراری می‌سازد                                                                                     | خرید مقدار ۲ با یک assignment؛ traceability و فروش بعدی خراب می‌شود                       | normalize و distinct قبل از validation و در Service؛ تعداد assignment باید با quantity برابر باشد                             |
| A15 | تاریخ انقضای متعارض Batch بی‌صدا نادیده گرفته می‌شود                         | `PurchaseService.php:360-375` فقط تعارض null/non-null مشترک را بررسی می‌کند                                                                                                                         | FEFO و alert با تاریخ اشتباه کار می‌کنند                                                  | الزام تطابق دقیق اطلاعات سری ساخت از جمله null؛ رد batch number خالی پس از trim                                               |
| A16 | ساختار حساب پس از استفاده تغییرپذیر است و CRUD حساب ندارد                    | `Account.php:118-174` فیلدهای code/type/parent/normal_balance را قفل نمی‌کند؛ `routes/web.php` هیچ route حساب ندارد؛ `AccountSeeder.php` تنها منبع ایجاد است                                        | تغییر تاریخی گزارش‌ها و شکستن account mapping؛ افزودن حساب بدون کد ممکن نیست              | CRUD حساب، قفل code/type/parent/normal_balance پس از گردش، اعتبارسنجی ماهیت و Account Pack                                    |
| A17 | لایهٔ tenant وجود ندارد                                                      | `config/database.php:20` یک connection ثابت؛ `bootstrap/app.php:19-29` resolver ندارد؛ ۴۲ جست‌وجوی hard-code `MAIN`                                                                                 | راست‌چین قابل اجرا نیست؛ cache با IDهای مشابه بین tenantها نشت می‌کند                     | Central DB، resolver قبل auth، dynamic connection، purge/reset و tenant-aware cache/session/queue/file/log                    |
| A18 | POS داخل هستهٔ حسابداری embedded است و Module Engine ندارد                   | `PostingService.php:14`، `JournalEntry.php:9`، `SalesService.php:20` به `PosPostingContext` وابسته‌اند                                                                                              | حذف POS یا افزودن ماژول بدون fork ممکن نیست                                               | قرارداد عمومی `PostingAuthorizationContext`؛ manifest/registry/lifecycle/profile                                              |
| A19 | کنترل‌های production آماده نیستند                                            | `.env.example:2-5` debug؛ `AppServiceProvider.php:42-49` سیاست رمز فقط production؛ scheduler/queue/agent دستی‌اند؛ README و deployment checklist وجود ندارد                                         | نصب نادرست، افشای خطا و نبود rollback/monitoring                                          | preflight تولید، service definitions، health/metrics، checklist و drill انتشار/rollback                                       |
| A20 | CI ممکن است concurrency tests را skip کند و سبز بماند                        | `ConcurrencySafetyTest.php:35-42`؛ workflow `tests.yml:45-51` صریحاً `pcntl` را تضمین نمی‌کند                                                                                                       | شاهد required نسخهٔ ۱ ممکن است ساخته نشود                                                 | تضمین `pcntl`، driver MySQL/MariaDB و fail-on-skip؛ گزارش تعداد test/assertion                                                |
| A21 | بستن دوره و یکپارچگی ماژول‌ها قابل توسعه نیست                                | `PeriodClosingService.php:398-445` فقط جدول‌های core را می‌شمارد؛ Events/Listeners/Jobs/Outbox وجود ندارد                                                                                           | draftهای ماژول مانع بستن دوره نمی‌شوند و اتصال بیرونی retry ندارد                         | `PeriodCloseBlocker` contributor، domain events و transactional outbox                                                        |
| A22 | صندوقدار می‌تواند نرخ VAT نهایی را override کند                              | `PosCheckoutRequest.php:7-28` از `SalesInvoiceRequest` ارث می‌برد؛ `SalesService.php:137-149` نرخ ارسالی را می‌پذیرد؛ POS با posting exception تأیید می‌کند                                         | فاکتور قطعی با مالیات دلخواه و بدون تأیید حسابدار                                         | tax rate مؤثر و سمت سرور؛ override فقط با مجوز، دلیل و Audit                                                                  |
| A23 | مرجوعی‌های جزئی تکراری می‌توانند تخفیف/مالیات/هزینه را بیش از اصل تخصیص دهند | `ReturnableQuantityService.php:55-82` مقدار قبلی تخصیص‌یافته را نادیده می‌گیرد؛ نمونه `0.05` در شش مرجوعی به `0.06` تبدیل می‌شود                                                                    | گردش مالی و COGS مرجوعی از فاکتور اصل بیشتر می‌شود                                        | تخصیص بر اساس باقی‌مانده و مقدار تجمعی؛ residual قطعی و تست چندمرجوعی                                                         |
| A24 | مرجوعی فروش/خرید پرداخت‌شده فقط credit می‌سازد و مسیر refund یا اعتبار ندارد | `SalesReturnService.php:604-638` همیشه AR را credit می‌کند؛ `PurchaseReturnService.php:609-652` همیشه AP را debit می‌کند؛ outstanding در `CustomerReceivableService.php:178-180` منفی را صفر می‌کند | credit در GL پنهان می‌ماند و مشتری/تأمین‌کننده نمی‌تواند آن را دریافت یا استفاده کند      | ماندهٔ علامت‌دار، refund کنترل‌شده یا credit application؛ تست مرجوعی POS نقدی/کارتی                                           |
| A25 | ثبت چک بیرونی تکراری جلوگیری نمی‌شود                                         | migration اولیه unique داشت؛ `2026_10_23_140001_harden_check_identity.php:12-20` آن را حذف کرد؛ `CheckRegistrationService.php:94-153` بررسی duplicate ندارد                                         | یک چک بانکی با idempotency key مختلف چندبار ثبت و تخصیص می‌یابد                           | unique یا هشدار/override دارای Audit برای هویت بیرونی store-scoped؛ تست concurrent                                            |
| A26 | Aging تاریخی پس از برگشت چک اشتباه می‌شود                                    | `AgingReportService.php:41-63,95-117` فقط status فعلی receipt/payment را می‌بیند؛ bounce در `CheckSettlementService.php:135-139` status را تغییر می‌دهد                                             | outstanding تاریخ گذشته بازسازی نادرست و ناسازگار با صورت‌حساب                            | تاریخچهٔ status immutable یا بازسازی از رویدادهای تاریخ‌دار؛ تست as-of قبل/بعد bounce                                         |
| A27 | غیرفعال‌کردن حساب P&L دارای گردش، بستن سال را غیرممکن می‌کند                 | `YearEndClosingService.php:237-243` حساب غیرفعال را در اختتامیه می‌آورد؛ `PostingService.php:286-290` آن را رد می‌کند                                                                               | سال مالی با یک تغییر master-data قفل می‌شود                                               | اجازهٔ حساب غیرفعال در سند اختتامیه یا منع غیرفعال‌سازی حساب دارای گردش باز                                                   |
| A28 | موجودی افتتاحیه workflow کنترل‌شده ندارد                                     | `InventoryMovementType::Opening` و `InventoryService::receive` وجود دارند؛ `ManualJournalService.php:513-525` posting دستی به حساب موجودی را ممنوع می‌کند؛ orchestrator افتتاحیه وجود ندارد         | بارگذاری موجودی اولیه بدون اختلاف subledger/GL ممکن نیست                                  | opening inventory اتمیک با movement و سند متوازن؛ گزارش تطبیق موجودی                                                          |
| A29 | rollback و retry چند migration دادهٔ واقعی را به خطر می‌اندازد               | `scope_existing_parties_to_store.php:12-14` up هیچ کاری نمی‌کند اما down ستون را drop می‌کند؛ migration Batch جدول غیرخالی را drop می‌کند؛ rollback enumها مقدار return/bounced را حذف می‌کند       | rollback می‌تواند ownership یا statusهای زنده را از بین ببرد                              | downهای متقارن، migration idempotent و expand/contract؛ تست rollback با دادهٔ واقعی                                           |
| A30 | آمادگی چندشعبه‌ای با فعال‌کردن routeها امن نیست                              | `BranchContextService.php:12-18` کاربر را نادیده می‌گیرد؛ `FiscalYearSetupService.php:177-199` فقط sequence شعبهٔ پیش‌فرض می‌سازد؛ unique شماره‌ها global است                                       | cross-branch access، برخورد شماره و race در آخرین شعبه                                    | per-branch sequence، scope مجوز، invariant اتمیک و uniqueهای مرکب پیش از فعال‌سازی                                            |
| A31 | uniqueهای global با scope Store/Branch سازگار نیستند                         | `accounts.code` در migration اولیه unique است؛ شمارهٔ journal/invoice/receipt/payment نیز global unique است                                                                                         | Store دوم نمی‌تواند همان chart را داشته باشد و branchها ممکن است شماره تکراری بسازند      | uniqueهای `(store_id, code)` و ابعاد مناسب شماره‌گذاری؛ deduplication قبل از migration                                        |

## ۱.۲ اولویت P2

| ID  | ایراد / ریسک                                                                             | شاهد                                                                                                                                                                              | اصلاح پیشنهادی                                                               |
| --- | ---------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| B01 | دریافت/پرداخت هم‌زمان با همان idempotency key ممکن است 500 بدهد                          | `CustomerReceiptService.php:107-116,227-249`؛ `SupplierPaymentService.php:102-111,230-252`                                                                                        | lock یا catch unique violation و بازخوانی document یکسان                     |
| B02 | ترتیب lockهای انبار می‌تواند deadlock بسازد                                              | `SalesService.php:253` بر اساس sort_order؛ `InventoryService.php:297`؛ `StockCountService.php:178-203`                                                                            | sort قطعی `product_id` و lock order واحد + retry deadlock                    |
| B03 | flagهای مجوز transition پس از save reset نمی‌شوند                                        | `Invoice.php:322`؛ `Receipt.php:185`؛ `Payment.php:185`؛ `Check.php:190`                                                                                                          | reset در `finally`؛ الگوی درست در `StockCount.php:114`                       |
| B04 | idempotency انبار فقط بخشی از ورودی‌ها را مقایسه می‌کند                                  | `InventoryService.php:943` هزینه، تاریخ، source، batch و serial را مقایسه نمی‌کند                                                                                                 | canonical request hash برای همهٔ ورودی‌ها                                    |
| B05 | `receive` با `serialIds` می‌تواند سریال available دارای movement قبلی را دوباره وارد کند | `InventoryService.php:558-579` مسیر serialIds بررسی `hasMovement` ندارد                                                                                                           | محدودکردن دریافت با serialIds به continuation انتقال/برگشت مشخص              |
| B06 | محصول یا طرف‌حساب غیرفعال، اصلاح تاریخی را مسدود می‌کند                                  | `InventoryService.php:235`؛ `SalesReturnService.php:481,566`؛ `CustomerReceiptService.php:120`                                                                                    | تفکیک «مجاز برای فروش جدید» از «مجاز برای اصلاح تاریخی»                      |
| B07 | کالای والد تنوع می‌تواند Draft غیرقابل تأیید بسازد                                       | `SalesService.php:115`؛ `PurchaseService.php:135`؛ والد فقط در `InventoryService.php:241` رد می‌شود                                                                               | اعتبارسنجی leaf product پیش از ساخت Draft و مصرف sequence                    |
| B08 | خرید با ۱۰۰٪ تخفیف Draft غیرقابل تأیید می‌سازد                                           | `PurchaseService.php:164` تخفیف مساوی gross را می‌پذیرد؛ `InventoryService.php:873` هزینهٔ صفر را رد می‌کند                                                                       | رد صریح پیش از Draft یا مسیر کنترل‌شدهٔ موجودی رایگان                        |
| B09 | code یک کالا می‌تواند با barcode کالای دیگر یکی باشد                                     | `SalesCheckoutLookupService.php:80` هر دو ستون را OR می‌کند؛ POS اولین نتیجه را می‌گیرد                                                                                           | cross-column uniqueness یا lookup دقیق barcode-first                         |
| B10 | فرم‌های عادی فروش/خرید از Number و float استفاده می‌کنند                                 | `resources/js/lib/number.ts:11`؛ `sales/Create.vue:123`؛ `purchases/Create.vue:117`                                                                                               | Decimal/BigInt مشترک برای preview و validation                               |
| B11 | POS موجودی منقضی را sellable نشان می‌دهد                                                 | `SalesCheckoutLookupService.php:66` همهٔ موجودی را جمع می‌کند؛ FEFO در `InventoryService.php:773` منقضی را حذف می‌کند                                                             | `sellable_quantity` جدا از total و نمایش مقدار quarantined                   |
| B12 | Draft مرجوعی جزئی فروش کل هزینه را snapshot می‌کند                                       | `SalesReturnService.php:208`؛ update و confirm در `355` و `551` اصلاح می‌کنند                                                                                                     | prorating در زمان ایجاد Draft                                                |
| B13 | acknowledgement alert انبار scope ندارد                                                  | `InventoryAlertController.php:11-14` رکورد route-bound را مستقیم update می‌کند                                                                                                    | resolve alert با store/branch فعال؛ cross-scope 404                          |
| B14 | Draftهای POS از session بسته در session جدید باز می‌شوند                                 | `pos/Index.vue:254-263,636-655` session_id را مقایسه نمی‌کند                                                                                                                      | quarantine Draftهای session دیگر؛ بازیابی صریح pending attempt               |
| B15 | retry بستن POS با شمارش متفاوت success برمی‌گرداند                                       | `PosSessionService.php:83-85` session بسته را بدون مقایسه برمی‌گرداند                                                                                                             | تطبیق countedCash/notes یا conflict                                          |
| B16 | تاریخ وصول/برگشت چک اعتبار زمانی ندارد                                                   | `CheckSettlementRequest.php:19-22`؛ `CheckBounceRequest.php:19-21`؛ `CheckSettlementService.php:87-202`                                                                           | رد تاریخ قبل از سند یا صدور چک                                               |
| B17 | completion هم‌زمان Print Agent race دارد                                                 | `PrintAgentController.php:54-77` status را بدون lock بررسی و event را خارج transaction می‌سازد                                                                                    | lock + atomic transition + event در همان transaction                         |
| B18 | خطای PDF فایل موقت pdf را پاک نمی‌کند                                                    | `NativePdfExportService.php:22-48` فقط html را در finally پاک می‌کند                                                                                                              | try/finally برای هر دو فایل                                                  |
| B19 | XLSX و چند گزارش کل داده را در RAM می‌خوانند                                             | `NativeXlsxExportService.php:33-101`؛ `PartyStatementReportService.php:51-61`؛ `AgingReportService.php:27-36`                                                                     | SQL aggregation/pagination و streaming worksheet                             |
| B20 | گزارش Batch فروش، مرجوعی را کم نمی‌کند                                                   | `InventorySalesAnalyticsService.php:159-177` فقط sale/out را جمع می‌کند                                                                                                           | include sales_return/in با علامت منفی یا join به اصل                         |
| B21 | حذف پروفایل کاربران POS با FK شکست می‌خورد                                               | `pos_sessions` migration؛ `UserDeletionService.php:14-29`                                                                                                                         | ترجیح deactivation/anonymization و حفظ actor تاریخی                          |
| B22 | Print Agent ویندوز HTML خام چاپ می‌کند                                                   | `RunPrintAgent.php:104-109`                                                                                                                                                       | render PDF در ویندوز یا اعلام پشتیبانی نشدن                                  |
| B23 | lifecycle ویرایش/ابطال و گزارش چک ناقص است                                               | `routes/web.php:178-183` فقط create/show/settle/bounce                                                                                                                            | ویرایش پیش از linkage و void دارای دلیل/Audit                                |
| B24 | Audit retention و alerting ندارد                                                         | `PROJECT-CONTEXT.md:692`؛ `AuditLog.php:44-52` append-only است                                                                                                                    | سیاست retention/archive و alert رویداد حساس                                  |
| B25 | scheduler ساعت تهران را رعایت نمی‌کند                                                    | `routes/console.php:11` UTC اجرا می‌شود؛ `.documents/schedule.md:91-94`                                                                                                           | `timezone('Asia/Tehran')` یا business clock                                  |
| B26 | بعضی lookupهای انبار branch scope ندارند                                                 | `SalesLookupController.php:31-40`؛ `InventoryAlertController.php:11-14`                                                                                                           | scope فعال branch پیش از multi-branch                                        |
| B27 | lifecycle Print Station ناقص است                                                         | `PrintJobService.php:25-31` آخرین station دیده‌شده را ترجیح می‌دهد حتی اگر offline باشد                                                                                           | online threshold، rotation/revocation و print idempotency                    |
| B28 | CSP و trusted host allowlist ندارد                                                       | `bootstrap/app.php:19-29`                                                                                                                                                         | trusted hosts و security-header/CSP tested                                   |
| B29 | خطای localStorage می‌تواند UI appearance را خراب کند                                     | `useAppearance.ts:51-114`                                                                                                                                                         | catch storage errors مانند panel color/menu layout                           |
| B30 | پوشش تست مستقیم سرویس‌ها ضعیف است                                                        | ۲۳ سرویس از ۶۱ سرویس هیچ ارجاع مستقیمی در تست ندارند                                                                                                                              | contract/domain test برای سرویس‌های بی‌تست؛ اولویت financial و inventory     |
| B31 | مستندات تحویل و هویت پکیج قدیمی هستند                                                    | `PROJECT-CONTEXT.md:13-15` کامیت قدیمی؛ `composer.json:2-4` هنوز starter kit؛ README وجود ندارد                                                                                   | به‌روزرسانی handoff، README و deployment/runbook                             |
| B32 | تغییر تاریخ/سال/شعبهٔ Draft سند، شمارهٔ قبلی را نگه می‌دارد                              | `ManualJournalService.php:175-186,233-239`؛ `DocumentSequence.php:19-27,83-103`                                                                                                   | قفل شماره پس از تخصیص یا بازتخصیص عند تغییر scope                            |
| B33 | hashهای idempotency در چند مسیر ناسازگار هستند                                           | returnها hash خام را overwrite می‌کنند؛ stock count آن را update نمی‌کند؛ POS/check/settlement بعضی retryها را مقایسه نمی‌کنند                                                    | canonical hash ثابت و مقایسهٔ همهٔ فیلدهای مادی در هر retry                  |
| B34 | posting کلید یکتای source-event و `posted_by` ندارد                                      | `create_journal_entries_table.php:22-31`؛ `PostingService.php:62-68`                                                                                                              | unique source-event و ثبت approver نهایی به‌صورت اتمیک                       |
| B35 | ترتیب بستن دوره و معنای soft-close تعریف نشده است                                        | `PeriodClosingService.php:61-78,196-217`؛ `PostingService.php:93-95`                                                                                                              | الزام ترتیب دوره، تاریخ مجاز، شمارهٔ دقیق و رفتار مجوز soft-close            |
| B36 | بستن سال metadata اصلی بستن دوره را overwrite می‌کند                                     | `YearEndClosingService.php:48-53,100-103,126-130,168-170`؛ `AccountingPeriod.php:93-97`                                                                                           | حفظ actor/time اصلی یا internal posting override به‌جای reopen               |
| B37 | ردیف‌های سند Audit نمی‌شوند و scope زیرمدل‌ها در گزارش Audit مبهم است                    | `config/audit.php:12-18` شامل `JournalEntryLine` نیست؛ `AuditLogService.php:40-42` store را از child نمی‌گیرد؛ `AuditLogReportService.php:184-188` null-store را برای همه می‌آورد | Audit ردیف سند با scope والد و archive/retention سازگار با append-only       |
| B38 | هویت طرف‌حساب پس از استفاده قابل تغییر است                                               | `PartyController.php:206-219`؛ `SalesInvoiceController.php:78-80,239-243`                                                                                                         | قفل code و snapshot هویت حقوقی/نمایش در اسناد نهایی                          |
| B39 | ردیف‌های tracking انبار و جمع allocationها محافظت ندارند                                 | `InventoryMovementBatch.php` و `InventoryMovementSerial.php` بدون guard؛ `InventoryService.php:454-498,642-673` جمع quantity/cost را کنترل نمی‌کند                                | append-only assignments، guard مانع منفی و تطبیق دقیق allocation با movement |
| B40 | header انتقال چندکالایی مقدار و واحد Cost متفاوت را جمع می‌کند                           | `InventoryTransferService.php:78,116-123`                                                                                                                                         | حذف aggregate quantity/unit cost مگر unitهای سازگار                          |
| B41 | alert انقضا نوع قدیمی را نگه می‌دارد و scope چندشععه‌ای دارد                             | `InventoryExpiryAlertService.php:44-68`؛ unique فقط `product_batch_id,type`؛ scheduler `withoutOverlapping` ندارد                                                                 | حذف نوع stale، scope branch/warehouse و جلوگیری از اجرای هم‌زمان             |
| B42 | ارزش موجودی کاتالوگ فیلتر دسته را اعمال نمی‌کند                                          | `ProductCatalogService.php:161-179` count فیلتر دارد ولی value ندارند                                                                                                             | یک scope مشترک برای count و value؛ تست چند دسته                              |
| B43 | تست MariaDB skip می‌شود و DB پیش‌فرض SQLite است                                          | `ConcurrencySafetyTest.php:40-42` فقط mysql؛ `config/database.php:20,67-85`                                                                                                       | پذیرش mysql/mariadb، CI matrix و fail-fast در production                     |
| B44 | قواعد کدینگ حساب اعتبارسنجی و مستندسازی نشده‌اند                                         | `Account.php:118-164` قالب کد و normal balance را کنترل نمی‌کند؛ Seeder و تست ۳۰ حساب دارند ولی مستندها ۲۶ می‌گویند                                                               | مستندسازی و تست طول/پیشوند/ماهیت؛ به‌روزرسانی تعداد حساب                     |
| B45 | وضعیت فعال Store/Branch یکدست enforce نمی‌شود                                            | `BranchContextService.php:12-18` فعال نیست؛ `PostingService.php:243-258` فقط ownership را بررسی می‌کند                                                                            | context فعال مرکزی و enforce در authorization/posting                        |
| B46 | گزارش دفتر کل و جریان نقد وجود ندارد                                                     | `FinancialReportsController.php:56-95` فقط معین و صورت‌های مالی؛ cash-flow service پیدا نشد                                                                                       | افزودن GL ledger و cash flow یا تصمیم صریح defer                             |

---

# بخش ۲ — امکانات پایهٔ کم‌موجود

| ID  | قابلیت پایه                            | وضعیت                             | کار لازم                                               | معیار پذیرش                                                    |
| --- | -------------------------------------- | --------------------------------- | ------------------------------------------------------ | -------------------------------------------------------------- |
| G01 | خدمت و کالای غیرانباری                 | ❌                                | `item_type`/non-stock policy و مسیر posting بدون انبار | فاکتور ترکیبی کالا و خدمت؛ فقط کالا movement/COGS داشته باشد   |
| G02 | ویژگی عمومی کالا                       | ❌ فقط رنگ/سایز ثابت              | Attribute Definition/Value با نوع متن، عدد و انتخاب    | تعریف برند/مدل/جنس و گزارش فروش بر اساس ویژگی                  |
| G03 | Registry واحد پایه                     | ❌ unit رشته‌ای                   | مدل Unit و انتخاب کنترل‌شده                            | واحدهای معتبر، snapshot تاریخی و رد واحد نامعتبر               |
| G04 | مدیریت درخت حساب                       | ❌ فقط Seeder                     | CRUD حساب، Account Pack و mapping قابل تنظیم           | افزودن حساب معین بدون کد؛ قفل ساختار پس از گردش                |
| G05 | گزارش مالیات دوره‌ای فروش/خرید         | ❌                                | گزارش VAT فروش، خرید، مرجوعی و تطبیق با دفتر           | مجموع گزارش با snapshot فاکتور و سند VAT برابر باشد            |
| G06 | گزارش عملیاتی خرید                     | ❌                                | گزارش عملکرد خرید و تأمین‌کننده                        | گزارش دوره‌ای قابل تطبیق با فاکتورها                           |
| G07 | شمارش و تعدیل سریال‌دار                | ❌ عمداً مسدود                    | reconciliation سریال expected/actual                   | missing/extra/sold/returned/void بدون از دست رفتن traceability |
| G08 | مدیریت کامل چند انبار                  | ⚠️ schema و transfer هست، UI ناقص | CRUD/scoped UI و گزارش تجمیعی                          | چند انبار در یک فروشگاه بدون تغییر schema                      |
| G09 | ویرایش/ابطال و گزارش چک                | ⚠️ lifecycle پایه هست             | اصلاح پیش از linkage، void و گزارش اختصاصی             | اشتباه غیرlinked اصلاح شود؛ settled immutable بماند            |
| G10 | First-run امن                          | ❌                                | setup یک‌بارهٔ transactional                           | نصب خالی فقط از UI قابل راه‌اندازی باشد                        |
| G11 | Backup/restore آزموده‌شده              | ❌                                | backup DB/فایل و restore drill                         | بازیابی کامل با تطبیق ledger/inventory/audit                   |
| G12 | lifecycle کاربر و 2FA                  | ❌                                | deactivation، session revocation و reset مدیریتی       | کاربر غیرفعال فوراً دسترسی نداشته باشد                         |
| G13 | Audit رویدادهای امنیتی                 | ❌                                | listener رویدادهای auth/2FA/passkey                    | login/reset/logout/2FA قابل گزارش بدون secret                  |
| G14 | پذیرش واقعی چاپ                        | ⚠️ زیرساخت هست                    | A4/A5، حرارتی ۵۸/۸۰، Agent و reconnect                 | تست دستی مدل‌های واقعی و سناریوی قطع شبکه                      |
| G15 | مستندات استقرار و production preflight | ❌                                | README، checklist، supervisor، health و rollback       | استقرار production-like قابل تکرار و قابل بازگشت               |
| G16 | workflow موجودی افتتاحیه               | ❌                                | orchestration اتمیک movement + سند افتتاحیه            | موجودی اولیه بدون اختلاف GL/subledger وارد شود                 |
| G17 | refund یا اعتبار مشتری/تأمین‌کننده     | ❌                                | مانعهٔ علامت‌دار، refund و credit application          | مرجوعی پرداخت‌شده قابل تسویه و قابل گزارش باشد                 |
| G18 | دفتر کل و جریان نقد                    | ❌                                | گزارش GL و cash flow                                   | گزارش‌ها با دفتر معین و خزانه تطبیق داده شوند                  |

> تبدیل پیشرفتهٔ واحد، تنوع ماتریسی، تخفیف پلکانی، اقساط، پورسانت، ضمانت،
> Bundle و تولید عمداً خارج از نسخهٔ پایه‌اند.

---

# بخش ۳ — آمادگی راست‌چین و معماری ماژولار

| ID  | ظرفیت هدف                                 | وضعیت | کار لازم                                                                    |
| --- | ----------------------------------------- | ----- | --------------------------------------------------------------------------- |
| M01 | Central Database و Tenant Database        | ❌    | مدل tenant/domain/connection، credential رمزنگاری‌شده و provisioning queued |
| M02 | resolve tenant قبل از auth                | ❌    | subdomain middleware، بررسی active/suspended/provisioning                   |
| M03 | dynamic connection و reset امن            | ❌    | `Config::set`، purge، default switch و reset در request/job/Octane          |
| M04 | tenant-aware session/cache/queue/file/log | ❌    | prefix/path/context مستقل و جداسازی admin مرکزی                             |
| M05 | rollout migration روی همهٔ tenantها       | ❌    | job fan-out، وضعیت per tenant، backup و rollback                            |
| M06 | Profile Engine                            | ❌    | manifest versioned، Seeder idempotent و dry-run                             |
| M07 | Module Engine                             | ❌    | registry، dependency، permission، route، menu، migration و lifecycle        |
| M08 | جداسازی POS از core                       | ❌    | جایگزینی `PosPostingContext` با قرارداد عمومی                               |
| M09 | Account Pack و account mapping پویا       | ❌    | contribution حساب‌ها و تشخیص تداخل                                          |
| M10 | Period Close contributor                  | ❌    | هر Module بتواند draft blocker خودش را ثبت کند                              |
| M11 | Permission و Navigation registry          | ❌    | حذف وابستگی به enum بسته و array ثابت منو                                   |
| M12 | Domain Event و Outbox                     | ❌    | event قابل audit و integration بیرونی قابل retry                            |
| M13 | Sales Channel به‌جای InvoiceType جدید     | ❌    | POS/manual/online/marketplace به‌عنوان کانال                                |
| M14 | حذف وابستگی‌های hard-code                 | ❌    | حذف ۴۲ جست‌وجوی `MAIN` و استفاده از Tenant/Store context                    |
| M15 | Morph map                                 | ❌    | جلوگیری از تغییر `source_type` و `auditable_type` هنگام جابه‌جایی مدل‌ها    |
| M16 | Uniqueهای scope-aware و چندشعبه‌ای امن    | ❌    | `(store_id, code)`، شماره‌گذاری per-branch، active branch و invariant اتمیک |
| M17 | Migration rollout امن                     | ❌    | expand/contract، rollback متقارن، retry و آزمون با دادهٔ واقعی              |

---

# بخش ۴ — قابلیت‌های اختیاری آینده

این موارد نباید داخل fork صنفی ساخته شوند:

| گروه          | قابلیت‌ها                                             |
| ------------- | ----------------------------------------------------- |
| فروش پیشرفته  | تخفیف پلکانی/شرطی/مخصوص مشتری، اقساط، پورسانت، Bundle |
| خدمات و ضمانت | خدمات پس از فروش، تعمیر، ضمانت، تاریخچهٔ دستگاه       |
| تولید         | BOM، سفارش تولید، مصرف مواد اولیه، ضایعات، Yield      |
| کانال‌ها      | مارکت‌پلیس، فروشگاه آنلاین، درگاه پرداخت              |
| یکپارچگی      | مودیان، پیامک، تطبیق بانکی، API عمومی                 |
| ERP           | حقوق و دستمزد، دارایی ثابت، CRM، بودجه، مرکز هزینه    |
| تجهیزات       | لیبل‌زن، بارکدخوان، ترازو، کارت‌خوان                  |
| سازمانی       | چندشعبه‌ای کامل، چندفروشگاهی                          |

---

# بخش ۵ — نقاط قوت پروژه

این موارد باید حفظ شوند:

- حسابداری دوبل، کنترل توازن و غیرقابل ویرایش بودن سند Posted
- سال مالی، قفل دوره و بستن/بازگشایی Audit
- دفتر حرکت انبار به‌عنوان منبع حقیقت و snapshot میانگین موزون
- COGS و برگشت هزینهٔ خروج در مرجوعی
- دریافت/پرداخت چندفاکتوری با allocation و lock
- POS اتمیک، session-locked و idempotent
- چک دریافتی/پرداختی با سند مستقل وصول/برگشت
- Audit Log با trigger پایگاه داده برای append-only
- CSV امن در برابر Formula Injection
- گزارش‌های مالی و عملیاتی و export CSV/XLSX/PDF
- ۳۵۰ تست موفق و تست هم‌زمانی طراحی‌شده برای MySQL/MariaDB

---

# بخش ۶ — ترتیب اجرای پیشنهادی

## فاز ۰ — تثبیت CI و امنیت وابستگی‌ها

1. ارتقای Laravel/Symfony/WebAuthn و بررسی شکست تست‌ها
2. ارزیای breaking upgrade برای `vite-plus` و رفع advisoryهای dev
3. افزودن `composer audit` و `npm audit` به CI
4. رفع فرمت Markdown یا استثنای مستندات از fmt
5. fail-on-skip تست concurrency

**خروجی:** `composer ci:check` سبز و audit پاک.

## فاز ۱ — اصلاح باگ‌های بحرانی روزمره

1. Business Clock تهران
2. منع override مالیات توسط صندوقدار
3. تطبیق rounding POS با سرور
4. رفع نشت دادهٔ داشبورد
5. ابطال session با تغییر رمز
6. HTTPS و reconnect/lease در Print Agent
7. نرمال‌سازی سریال و تطبیق اطلاعات Batch
8. اصلاح تخصیص پولی مرجوعی‌های جزئی تکراری
9. مسیر refund/credit برای مرجوعی پرداخت‌شده
10. جلوگیری از چک بیرونی تکراری و اعتبار زمانی چک
11. رفع raceهای completion، receipt/payment و lock ordering

**خروجی:** سناریوهای POS، مرجوعی، چاپ، دریافت/پرداخت و سریال بدون خطای تأییدشده.

## فاز ۲ — بستن blockerهای نسخهٔ ۱

1. First-run setup
2. Backup/restore و retention
3. deactivation کاربر و reset مدیریتی 2FA
4. Audit رویدادهای امنیتی و ردیف‌های سند
5. پشتیبانی service/non-stock
6. workflow موجودی افتتاحیه و تطبیق GL/subledger
7. رفع بستن سال با حساب غیرفعال و حفظ metadata بستن دوره
8. ایمن‌سازی migration/rollback و uniqueهای scope-aware
9. production preflight، README و runbook
10. پذیرش UI، Firefox و چاپگر واقعی

**خروجی:** checklist انتشار نسخهٔ ۱ کامل شود.

## فاز ۳ — تکمیل پایهٔ چندصنفی

1. Attribute Definition/Value
2. Unit registry
3. CRUD حساب و Account Pack
4. گزارش VAT، خرید، دفتر کل و جریان نقد
5. شمارش سریال‌دار، چند انبار و lifecycle چک
6. snapshot هویت طرف‌حساب و قفل ساختار حساب
7. append-only tracking انبار و تطبیق allocationها
8. بهینه‌سازی report/export و رفع analytics مرجوعی

**خروجی:** نسخهٔ پایه برای عمومی، پوشاک و خواروبار آماده باشد.

## فاز ۴ — زیرساخت راست‌چین و ماژول

1. Central/Tenant DB و resolver
2. tenant-aware state
3. provisioning و migration rollout
4. Profile/Module registry
5. جداسازی POS از core
6. Event/Outbox و contributorهای بستن دوره

**خروجی:** دو tenant جدا و چند Profile/Module بدون fork کد.

## فاز ۵ — ماژول‌های تجاری

- تخفیف پیشرفته و اقساط
- ضمانت/تعمیر
- تولید
- مارکت‌پلیس و فروشگاه آنلاین
- حقوق و دستمزد
- مودیان، پیامک و درگاه

---

# بخش ۷ — تصمیم‌های لازم از کاربر/محصول

1. سیاست کالای رایگان و خرید ۱۰۰٪ تخفیف‌دار
2. مجاز بودن اصلاح تاریخی برای محصول/طرف‌حساب غیرفعال
3. حذف کاربر یا deactivation/anonymization
4. روش backup و retention نهایی
5. اولین صنف‌های واقعی بازار
6. بسته‌بندی Module: داخلی، Composer یا ZIP امضاشده
7. دامنهٔ قانونی ماژول داروخانه
8. topology production برای MySQL، Redis، Queue و Object Storage

---

# بخش ۸ — جمع‌بندی

اولویت مطلق باید **رفع باگ‌های تأییدشده و بستن blockerهای انتشار** باشد، نه
افزودن ماژول جدید. بعد از تثبیت نسخهٔ پایه، معماری tenant و Module را باید
پیاده کرد و در نهایت قابلیت‌های صنفی را روی همین هسته اضافه کرد.
