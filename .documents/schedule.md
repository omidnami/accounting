# زمان‌بندی حساب‌یار

آخرین بازبینی: ۲۰۲۶-۱۰-۲۴

این سند مرجع زمان‌بندی‌های واقعی پروژه است. بعد از هر تغییر در
`routes/console.php` یا Commandهای زمان‌بندی‌شده، این فایل هم باید به‌روز شود.

## منطقهٔ زمانی

تنظیم فعلی برنامه:

```text
APP_TIMEZONE=UTC
```

زمان‌بندی‌ها مگر با `timezone()` مشخص نشوند، بر اساس UTC اجرا می‌شوند.
زمان نمایش‌داده‌شده در رابط کاربری و گزارش‌های فارسی مستقل از این تنظیم است.

## زمان‌بندی‌های فعال

### هشدار انقضای Batch

```text
Command:  inventory:refresh-expiry-alerts
Frequency: روزانه
Cron:     0 7 * * *
Timezone: UTC
Tehran:   حدود ساعت ۱۰:۳۰
```

این Command:

- موجودی Batchهای دارای موجودی بیشتر از صفر را بررسی می‌کند.
- برای Batchهای منقضی‌شده هشدار `expired` می‌سازد.
- برای Batchهایی که تا ۳۰ روز آینده منقضی می‌شوند هشدار `expiring` می‌سازد.
- هشدارهای تکراری Batch ایجاد نمی‌کند.
- هشدارهای Batchهایی را که دیگر موجودی ندارند حذف می‌کند.
- هشدار «بررسی‌شده» را در اجرای مجدد به‌صورت خودکار باز نمی‌کند.

اجرای دستی:

```bash
php artisan inventory:refresh-expiry-alerts
```

مشاهدهٔ Scheduleهای ثبت‌شده:

```bash
php artisan schedule:list
```

نتیجهٔ فعلی:

```text
0 7 * * * php artisan inventory:refresh-expiry-alerts
```

## اجرای Scheduler در Production

Laravel Scheduler باید هر دقیقه توسط Cron سیستم‌عامل اجرا شود. یک بار در
Crontab سرور ثبت کنید:

```cron
* * * * * cd /path/to/accounting-system && php artisan schedule:run >> /dev/null 2>&1
```

مسیر واقعی پروژه و مسیر PHP را متناسب با سرور جایگزین کنید.

بررسی سلامت اجرای Cron:

```bash
php artisan schedule:list
```

برای نگه‌داری لاگ:

```cron
* * * * * cd /path/to/accounting-system && php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

در محیط توسعه می‌توان Scheduler را به‌صورت دائمی اجرا کرد:

```bash
php artisan schedule:work
```

`schedule:work` جایگزین Cron در Production نیست.

## نکات عملیاتی

- قبل از Deploy، `php artisan schedule:list` باید Command هشدار انقضا را نشان دهد.
- اگر ساعت اجرای روزانه باید دقیقاً ۰۷:۰۰ به وقت تهران باشد، باید صراحتاً
  `->timezone('Asia/Tehran')` به تعریف Schedule اضافه شود؛ تنظیم فعلی UTC است.
- اجرای دوبارهٔ Command برای هر روز بی‌خطر است.
- Command هشدار انقضا نباید به‌صورت موازی با نسخهٔ دیگری از خودش اجرا شود؛
  Cron فقط `schedule:run` را یک بار اجرا کند.
- Batchهای بدون تاریخ انقضا هشدار انقضا ایجاد نمی‌کنند.
- برای دریافت هشدار، موجودی Batch باید در `inventory_batch_balances` بیشتر از
  صفر باشد.

## سرویس‌های غیرزمان‌بندی‌شده

### Print Agent

Print Agent یک Daemon محلی است و جزء Scheduler سرور نیست:

```bash
php artisan print-agent:run \
  --server=https://your-domain.example \
  --token=STATION_TOKEN \
  --printer="Printer Name"
```

در Production باید به‌عنوان سرویس سیستم‌عامل یا Process Manager روی دستگاه
صندوق اجرا و مجدداً راه‌اندازی شود.

### Queue Worker

Queue Worker نیز خارج از Scheduler اجرا می‌شود:

```bash
php artisan queue:work --tries=3 --timeout=60
```

در Production باید Supervisor یا Process Manager مشابه، Worker را فعال نگه دارد.
