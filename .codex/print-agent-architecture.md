# Print Agent Architecture

آخرین به‌روزرسانی: ۲۰۲۶-۱۰-۲۴

## تصمیم محصول

حساب‌یار یک محصول SaaS است و نمی‌تواند مدل چاپگر یا شبکهٔ محلی مشتری را از قبل
بداند. بنابراین چاپ در دو سطح تعریف می‌شود:

1. **چاپ عمومی از مرورگر:** مسیر فعال فعلی. کاربر چاپگر نصب‌شده در سیستم‌عامل
   را از پنجره Print انتخاب می‌کند.
2. **Print Agent اختیاری:** مسیر فاز بعد برای چاپ بی‌صدا، صف محلی و گزارش
   وضعیت چاپگر بدون اتصال مستقیم سرور ابری به شبکهٔ فروشگاه.

سرور ابری نباید مستقیماً به USB، Bluetooth یا IP چاپگر داخل شبکهٔ فروشگاه
متصل شود. WebUSB/Web Serial نیز قابلیت اصلی محصول نیست؛ پشتیبانی مرورگر،
سیستم‌عامل و چاپگرها محدود است.

## معماری آینده

```text
Cloud Print API
      |
      | outbound HTTPS long-poll / stream
      v
Print Agent on cashier workstation
      |
      +-> OS spooler (Windows / CUPS)
      |
      +-> supported ESC/POS adapter
      v
Local thermal printer
```

Agent فقط اتصال خروجی به سرور دارد و هیچ پورت ورودی روی مودم یا شبکهٔ فروشگاه
باز نمی‌کند.

## قرارداد Print Job

```json
{
    "id": "uuid",
    "store_id": 1,
    "branch_id": 1,
    "station_id": "uuid",
    "document_type": "sales_invoice",
    "document_id": 123,
    "template": "sales_receipt",
    "template_version": 1,
    "payload": {},
    "paper_width_mm": 80,
    "copies": 1,
    "expires_at": "ISO-8601",
    "signature": "HMAC"
}
```

قواعد:

- شناسه Job یکتا و idempotent است.
- Payload از snapshot قابل‌چاپ سند ساخته می‌شود، نه از Queryهای آزاد Agent.
- هر Job فقط برای Station مقصد قابل claim است.
- Job منقضی‌شده چاپ نمی‌شود.
- Agent نتیجه را با `claimed / printed / failed` و دلیل خطا گزارش می‌کند.
- امضای Job و توکن Station جداگانه کنترل می‌شوند.

## چرخه وضعیت

```text
queued -> claimed -> printed
                 \-> failed -> queued (retry محدود)
```

چاپ دوباره فقط با retry کنترل‌شده یا دستور صریح کاربر مجاز است.

## Pairing و امنیت

- مدیر یک کد Pairing کوتاه‌عمر برای شعبه می‌سازد.
- Agent کد را با نام دستگاه و platform به API ارسال می‌کند.
- سرور توکن Station را یک‌بار برمی‌گرداند؛ فقط hash توکن ذخیره می‌شود.
- امکان revoke فوری Station وجود دارد.
- Jobها TTL کوتاه، امضا و scope فروشگاه/شعبه دارند.
- IP، نسخه Agent، آخرین اتصال و وضعیت هر Station قابل مشاهده است.
- دادهٔ چاپ حساس در log خام ثبت نمی‌شود.

## مدل داده پیشنهادی در فاز Agent

- `print_stations`: شعبه، نام دستگاه، platform، توکن hash، وضعیت و last_seen
- `print_jobs`: سند، Station، وضعیت، template version، expiry، attempts و خطا
- `print_events`: رخدادهای claim/print/fail برای Audit و عیب‌یابی

تنظیمات پایهٔ شعبه در `branches.thermal_paper_width` و
`branches.thermal_copies` نگهداری می‌شود. تنظیم چاپگر پیش‌فرض، encoding و
driver در Agent محلی نگهداری خواهد شد؛ چون به دستگاه فیزیکی وابسته است.

## قابلیت‌های Agent

### نسخه اول Agent

- Windows
- اتصال خروجی HTTPS
- انتخاب چاپگر پیش‌فرض
- چاپ از OS spooler
- صف محلی کوتاه برای قطعی اینترنت
- retry محدود و idempotent
- نمایش وضعیت اتصال و آخرین چاپ

### نسخه‌های بعدی

- CUPS/Linux
- Android و Bluetooth
- ESC/POS خام برای چاپگرهای تأییدشده
- کشیدن فاکتور/گزارش و QR
- auto-update امضاشده

## خارج از محدوده این فاز

- ساخت و توزیع Agent
- بازکردن چاپگر روی اینترنت
- WebUSB/Web Serial
- پشتیبانی تضمین‌شده از مدل خاص چاپگر
- چاپ سمت سرور روی شبکهٔ خصوصی مشتری

## معیارهای پذیرش Agent

- چاپ دوباره با همان Job اثر تکراری ایجاد نکند.
- Station فروشگاه دیگر نتواند Job را claim کند.
- Job منقضی یا توکن revoke‌شده رد شود.
- قطع اینترنت باعث crash یا چاپ نامحدود نشود.
- نتیجه چاپ در سیستم و Audit قابل ردیابی باشد.
- نسخه ۵۸ و ۸۰ میلی‌متر با چاپگر واقعی پذیرش شوند.
