# Phase 15D - شمارش، تعدیل و انتقال انبار

آخرین به‌روزرسانی: ۲۰۲۶-۱۰-۲۴

## شمارش موجودی

- مسیرهای `/inventory/counts`
- ایجاد پیش‌نویس شمارش برای یک انبار و چند کالا
- ویرایش و حذف Draft توسط نقش مجاز
- مقایسه مقدار سیستم و مقدار شمارش‌شده
- ثبت نهایی فقط توسط مدیر یا حسابدار
- محاسبه مجدد موجودی سیستم در زمان ثبت نهایی
- قفل InventoryBalance قبل از محاسبه اختلاف
- چند کالا در یک transaction
- idempotency برای ایجاد و حرکت هر کالا
- سند تعدیل برای مازاد/کسری و اتصال morph به شمارش

## حساب‌های تعدیل

- `4104`: درآمد تعدیل موجودی
- `5107`: کسری و اختلاف موجودی

حرکت مازاد:

```text
بدهکار: موجودی کالا
بستانکار: درآمد تعدیل موجودی
```

حرکت کسری:

```text
بدهکار: کسری و اختلاف موجودی
بستانکار: موجودی کالا
```

## انتقال انبار

- مسیرهای `/inventory/transfers`
- انتقال یک یا چند کالا بین دو انبار فعال یک شعبه
- استفاده از `InventoryService::transfer`
- دو حرکت ورود/خروج با یک transaction
- idempotency برای جلوگیری از تکرار
- ذخیره snapshot بهای واحد و ارزش انتقال
- اتصال movementهای ورود/خروج به سند انتقال

## دسترسی و کنترل داده

- مدیر و حسابدار: شمارش، ثبت تعدیل و انتقال
- انباردار: ایجاد/ویرایش شمارش خودش و انتقال، بدون ثبت تعدیل
- انباردار نمی‌تواند بهای واحد را از payload تعیین یا ببیند
- انباردار فقط Draft شمارش ساخته‌شده توسط خودش را ویرایش/حذف می‌کند
- قیمت و ارزش انتقال برای انباردار در response ساخته نمی‌شود
- شمارش Draft مانع بستن دوره مالی است

## فایل‌های اصلی

- `app/Services/Inventory/StockCountService.php`
- `app/Services/Inventory/InventoryTransferService.php`
- `app/Http/Controllers/StockCountController.php`
- `app/Http/Controllers/InventoryTransferController.php`
- `app/Models/StockCount.php`
- `app/Models/StockCountItem.php`
- `app/Models/InventoryTransfer.php`
- `resources/js/pages/inventory/counts`
- `resources/js/pages/inventory/transfers`
- `database/migrations/2026_10_24_000001_create_stock_counts_and_inventory_transfers.php`
- `tests/Feature/Accounting/StockCountAndTransferTest.php`

## بررسی‌ها

- Pest: `276 tests`, `2060 assertions`
- Node calendar: `3 tests`
- PHPStan: بدون خطا
- Pint: موفق
- Vue lint/format و type-check: موفق
- Frontend build: موفق
- migration روی SQLite اجرا شد؛ MySQL پس از push در GitHub Actions بررسی
  خواهد شد.

## محدودیت‌های باز

- انتقال چندکالایی است و فقط داخل یک شعبه انجام می‌شود.
- شمارش از lookup جست‌وجوشونده و ورود CSV پشتیبانی می‌کند.
- بررسی دستی مرورگر انجام نشده است.
- برای دیتابیس موجود باید migration اجرا و Account Seeder دوباره اجرا شود
  تا دو حساب تعدیل ساخته شوند.

## اصلاحات بازبینی

- نام index چندستونی انتقال انبار به `inventory_transfers_route_idx` کوتاه
  شد؛ نام خودکار قبلی از محدودیت ۶۴ کاراکتری MySQL بیشتر بود.
