# Phase 11A: Audit Log و مدیریت کاربران

وضعیت: پیاده‌سازی و تست محلی تکمیل شد؛ commit توسط کاربر انجام می‌شود.

## هدف

ثبت تغییرات مدل‌های حساس همراه با کاربر، زمان، IP و مقادیر قبل/بعد و مدیریت
نقش‌های کاربری فقط توسط مدیر، بدون افشای داده حساس.

## Audit Log

- جدول `audit_logs` با store/branch اختیاری، actor، event، morph، description،
  old/new values، IP و user agent.
- Observer مشترک روی Account، FiscalYear، AccountingPeriod، Party، Product،
  InventoryMovement، Invoice، InvoiceItem، JournalEntry، Receipt، Payment،
  Allocationها، Check، TreasuryAccount و User.
- رکورد Audit غیرقابل ویرایش و حذف از لایه مدل است.
- Password، remember token، 2FA secret و recovery codes حذف یا redacted
  می‌شوند.
- صفحه `/audit-logs` با فیلتر کاربر/موجودیت/رویداد/زمان و نمایش قبل و بعد.
- دسترسی فقط برای `manager`.

## مدیریت کاربران

- صفحه `/users` برای فهرست، ایجاد و ویرایش کاربر.
- نقش‌های manager، accountant، cashier، warehouse و viewer.
- ایجاد کاربر به‌صورت verified انجام می‌شود.
- مدیر نمی‌تواند نقش حساب خودش را تغییر دهد.
- آخرین مدیر سیستم قابل تنزل به نقش دیگر نیست.
- ویرایش کاربر با `version` از overwrite هم‌زمان محافظت می‌شود.
- تغییر نام، ایمیل، نقش یا رمز در Audit Log ثبت می‌شود.
- دسترسی مدیریت فقط برای `manager`.

## مسیرها

- `GET/POST /users`
- `GET /users/create`
- `GET/PUT/PATCH /users/{user}/edit|update`
- `GET /audit-logs`

## تست

- کنترل دسترسی مدیر، حسابدار و مهمان.
- ایجاد کاربر verified و ثبت Audit با actor.
- Redaction رمز عبور در create/update.
- تغییر نقش و رمز با version.
- رد ویرایش stale و تغییر نقش خود.
- فیلتر Audit بر اساس event/entity.
- immutability رکورد Audit.

## نتیجه محلی

- Pest: 220 تست موفق، 1554 assertion
- PHPStan: صفر خطا
- Pint، Vue lint و type-check: موفق
- Frontend build: موفق

## محدودیت‌های باقی‌مانده

- ثبت‌نام عمومی همچنان فعال است؛ کاربران ثبت‌نامی نقش پیش‌فرض `viewer` دارند.
- غیرفعال‌سازی/قفل کاربر و reset اجباری 2FA در UI وجود ندارد.
- Export، retention policy و alerting برای Audit پیاده‌سازی نشده است.
- بررسی دستی مرورگر و MySQL CI روی commit جدید باقی مانده است.
