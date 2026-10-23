# Phase 15A - مدیریت سال مالی و rollover

آخرین به‌روزرسانی: ۲۰۲۶-۱۰-۲۳

## دامنه تکمیل‌شده

- مسیر `/fiscal-years` فقط برای نقش `manager`
- ساخت سال مالی شمسی از UI
- ایجاد خودکار ۱۲ دوره مالی
- ایجاد sequenceهای مستقل اسناد
- پیشنهاد اولین سال شمسی استفاده‌نشده
- نمایش سال‌ها، بازه تاریخ و وضعیت هر دوره
- تعیین کنترل‌شده سال جاری

## منطق سال جاری

- ساخت سالی که امروز داخل بازه آن است، آن را جاری می‌کند و سال جاری قبلی را
  غیرجاری می‌کند.
- ساخت سال آینده، سال جاری فعلی را تغییر نمی‌دهد.
- مدیر می‌تواند یک سال باز را به‌صورت دستی جاری کند.
- سال مالی بسته یا در حال بستن جاری نمی‌شود.
- تغییر سال جاری با قفل فروشگاه سریال می‌شود و فقط یک سال جاری باقی
  می‌ماند.
- هم‌پوشانی احتمالی در Service و invariant مدل کنترل می‌شود.

## دسترسی

- `manager`: ساخت و تعیین سال جاری
- سایر نقش‌ها: 403

## فایل‌های اصلی

- `app/Services/Accounting/FiscalYearSetupService.php`
- `app/Http/Controllers/FiscalYearController.php`
- `app/Http/Requests/StoreFiscalYearRequest.php`
- `resources/js/pages/fiscal-years/Index.vue`
- `tests/Feature/Accounting/FiscalYearManagementTest.php`

## بررسی‌ها

- Pest: `245 tests`, `1879 assertions`
- Node calendar: `3 tests`
- PHPStan: بدون خطا
- Pint: موفق
- Vue lint/format و type-check: موفق
- Frontend build: موفق
- migration جدید لازم نبود

## محدودیت‌های باز

- بستن/بازگشایی دوره و سال در Phase 15C انجام می‌شود.
- سند اختتامیه و افتتاحیه هنوز وجود ندارد.
- بررسی دستی صفحه در مرورگر انجام نشده است.
- sequenceهای خودکار همچنان برای شعبه پیش‌فرض MVP ساخته می‌شوند.
