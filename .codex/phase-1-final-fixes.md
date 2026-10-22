# اصلاحات پایانی مرحله ۱

وضعیت کلی: تکمیل شد

## چک‌لیست

- [x] بررسی مجدد فعال و معین بودن تمام حساب‌های سند در لحظه قطعی‌سازی
- [x] قفل‌کردن حساب‌ها در زمان بررسی نهایی
- [x] افزودن تست غیرفعال‌شدن حساب بین Draft و Posted
- [x] افزودن MySQL service به GitHub Actions
- [x] تنظیم صحیح متغیرهای اتصال و extensionهای PHP در CI
- [x] اجرای Pint، تست کامل و بررسی workflow

## گزارش

### وضعیت اولیه

- کارهای قبلی در commit `15ce255` ثبت شده‌اند.
- تست فعلی: ۷۶ موفق و ۲۴۶ assertion.
- CI در `composer setup` قبل از اجرای تست‌ها به MySQL متصل می‌شود و سرویس دیتابیس در workflow تعریف نشده است.

### اصلاحات انجام‌شده

- هنگام `finalizePosting()` همه ID حساب‌های ردیف‌ها دوباره خوانده می‌شوند.
- حساب‌ها با `lockForUpdate` قفل می‌شوند.
- وجود، فعال بودن، سطح معین و محدوده فروشگاه همه حساب‌ها دوباره بررسی می‌شود.
- تست غیرفعال‌شدن حساب بعد از ساخت پیش‌نویس و قبل از ثبت نهایی اضافه شد.
- GitHub Actions یک MySQL 8.4 service با health check، database و credentials مشخص دارد.
- extensionهای `bcmath`، `mbstring`، `pdo_mysql` و `pdo_sqlite` در CI نصب می‌شوند.
- annotationهای Larastan برای Factoryها، رابطه‌ها، enumها، تاریخ‌ها و Builderها تکمیل شدند.
- `Decimal` برای PHPStan و bcmath به‌صورت `numeric-string` تایپ شد.

### گزارش نهایی

- `composer ci:check`: موفق
- Pint: موفق
- PHPStan: صفر خطا
- Pest: ۷۷ تست موفق، ۲۴۸ assertion
- YAML workflow: معتبر
- اجرای واقعی GitHub پس از push، تایید نهایی سبزبودن remote را مشخص می‌کند.
