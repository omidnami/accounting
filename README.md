# سامانهٔ حسابداری حساب‌یار

سامانهٔ وب حسابداری دوطرفه، درخت حساب، طرف‌حساب، کالای انباری/غیرانباری/خدمت،
خرید و فروش، مرجوعی، انبار و COGS، خزانه و چک، POS، گزارش‌ها، RBAC و Audit.

معماری پایه طبق تصمیم‌های ثبت‌شده در `.codex/` نگه داشته شده است: یک codebase،
دو حالت استقرار. نصب جداگانه برای هر کسب‌وکار و آتی‌ترین نصب راست‌چین با DB
جداگانه به ازای tenant. لایهٔ کامل tenant/module engine هنوز بخشی از roadmap است.

## پیش‌نیازها

- PHP 8.3+ با `bcmath`، `mbstring`، `pdo_mysql`، `pcntl` و `Phar`
- MySQL 8.0+ یا MariaDB 10.11+
- Node 22+
- Composer 2
- `openssl` و `mysqldump` برای backup
- Chrome/Chromium و `lp` برای Print Agent لینوکس

## نصب محلی

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
npm install
npm run build
php artisan serve
```

سپس `/setup` را باز کنید. در production حتماً `SETUP_TOKEN` را تنظیم کنید و از
آدرس `/setup?token=...` استفاده کنید. این مسیر پس از ایجاد اولین کاربر 404 می‌دهد.

## متغیرهای مهم production

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://accounting.example.com
APP_KEY=...
BUSINESS_TIMEZONE=Asia/Tehran
DB_CONNECTION=mysql
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
SETUP_TOKEN=...
BACKUP_PASSPHRASE=...
BACKUP_RETENTION_DAYS=30
BACKUP_ALERT_EMAIL=ops@example.com
AUDIT_RETENTION_DAYS=365
APP_TRUSTED_HOSTS=accounting.example.com
TENANCY_ENABLED=false
TENANCY_CENTRAL_DOMAINS=localhost,127.0.0.1
```

پیش از انتشار:

```bash
php artisan system:preflight
php artisan migrate --force
php artisan optimize
composer audit --locked
npm audit --omit=dev
```

## Scheduler و Queue

Cron زیر را با کاربر صحیح سیستم اجرا کنید:

```cron
* * * * * cd /var/www/accounting && php artisan schedule:run >> /dev/null 2>&1
```

Worker صف:

```bash
php artisan queue:work --tries=3 --timeout=120
```

زمان‌بندی‌های داخلی با منطقهٔ زمانی کسب‌وکار اجرا می‌شوند:

- هر دقیقه `outbox:process`
- `02:00` backup رمزنگاری‌شده
- `03:00` بررسی تازگی backup
- `04:00` روز اول ماه archive و prune Audit Log
- `07:00` تازه‌سازی هشدارهای انقضا

برای پردازش دستی outbox و archive:

```bash
php artisan outbox:process
php artisan audit:archive --force
```

## Backup و Restore

```bash
php artisan backup:run
php artisan backup:status
php artisan backup:restore /path/to/backup.bak.enc --database --files --force
```

هر backup شامل dump دیتابیس و فایل‌های `storage/app/public` و
`storage/app/private` است؛ فایل با AES-256-CBC + PBKDF2 رمزنگاری و با HMAC
اصالت سنجی می‌شود. passphrase را خارج از repository و در secret manager نگه دارید.

Restore در production بدون `--force` اجرا نمی‌شود. پیش از restore واقعی، ابتدا
روی یک محیط جدول بازیابی و تطبیق GL/subledger را آزمایش کنید.

## Print Agent

```bash
php artisan print-agent:run \
  --server=https://accounting.example.com \
  --token=PRINT_STATION_TOKEN \
  --printer=THERMAL_PRINTER
```

HTTP فقط برای `localhost` مجاز است. agent دارای retry/backoff، heartbeat، lease و
completion idempotent است. ویندوز در نسخهٔ فعلی HTML خام را با `Out-Printer` چاپ
می‌کند؛ برای چاپ حرفه‌ای ویندوزی باید renderer PDF اختصاصی اضافه شود.

مدیریت Print Station در `/settings/print` انجام می‌شود: چرخش توکن فقط یک‌بار توکن
جدید را نمایش می‌دهد و ابطال، jobهای `queued/claimed` باقی‌مانده را `failed` می‌کند.
توکن قدیمی پس از چرخش یا ابطال در همهٔ endpointهای agent با ۴۰۱ رد می‌شود.

## پذیرش دستی Chrome و Firefox

- `/checks`: ویرایش و ابطال چک `pending` بدون اتصال؛ رد عملیات برای چک متصل،
  وصول‌شده، برگشتی یا ابطال‌شده و نمایش درست وضعیت «ابطال‌شده».
- `/settings/print`: نمایش never-seen/online/offline، چرخش و ابطال station و
  نمایش یک‌بارمصرف توکن تازه.
- `/receipts/create` و `/payments/create`: انتخاب طرف‌حساب غیرفعال با برچسب
  «تسویه تاریخی» و ادامهٔ تسویهٔ فاکتور باز همان فروشگاه.
- `/sales` و `/purchases`: پس از ویرایش نام طرف‌حساب، صفحه و چاپ اسناد نهایی
  همچنان هویت snapshotشده را نشان دهند.
- `/reports`: دفتر کل، جریان نقد و aging بدون خطای JavaScript در هر دو مرورگر.

## تست و CI

```bash
composer ci:check
php artisan test tests/Integration/ConcurrencySafetyTest.php --fail-on-skipped
```

CI فعلی MySQL 8.4 و MariaDB 11.4، `pcntl`، audit وابستگی‌ها، PHPStan، Pint،
TypeScript، build و تست‌های هم‌زمانی را پوشش می‌دهد. تست هم‌زمانی روی SQLite skip
می‌شود و در CI با `--fail-on-skipped` نباید skip شود.

## Tenancy و Moduleها

در حالت نصب جداگانه، `TENANCY_ENABLED=false` کافی است. برای راست‌چین، tenant در
Central DB ثبت می‌شود و resolver پیش از auth/session، connection دیتابیس و
پیشوند cache/session و مسیر file/log را به tenant فعال تغییر می‌دهد.

فقط ماژول‌های موجود در `config/modules.php` مجازند؛ ZIP/PHP ارسالی tenant اجرا
نمی‌شود. lifecycle ماژول per-store در `store_modules` ذخیره می‌شود.

## Rollback انتشار

1. ترافیک را به نسخهٔ قبل هدایت کنید.
2. migrationهای destructive را فقط با دادهٔ واقعی و backup آزموده‌شده rollback کنید.
3. `php artisan backup:status` را بررسی کنید.
4. health endpoint `/up` و یک ورود/POS/چاپ واقعی را smoke-test کنید.
