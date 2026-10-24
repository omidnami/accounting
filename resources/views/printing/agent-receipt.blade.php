<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        @page { size: {{ $paperWidth }}mm auto; margin: 4mm; }
        body { font-family: sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 16px; text-align: center; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 2px; border-bottom: 1px dashed #999; text-align: right; }
        .total { font-weight: bold; font-size: 13px; }
        .copy { page-break-after: always; }
    </style>
</head>
<body>
@for ($copy = 0; $copy < $copies; $copy++)
    <section @class(['copy' => $copy < $copies - 1])>
        <h1>{{ $payload['seller']['name'] ?? 'حساب‌یار' }}</h1>
        <p>شعبه: {{ $payload['seller']['branch'] ?? '—' }}</p>
        <p>فاکتور: <span dir="ltr">{{ $payload['invoice_number'] ?? '—' }}</span></p>
        <p>تاریخ: {{ $payload['invoice_date'] ?? '—' }}</p>
        <p>مشتری: {{ $payload['customer']['name'] ?? '—' }}</p>
        <table>
            <thead><tr><th>کالا</th><th>تعداد</th><th>جمع</th></tr></thead>
            <tbody>
            @foreach (($payload['items'] ?? []) as $item)
                <tr>
                    <td>{{ $item['name'] }}</td>
                    <td>{{ $item['quantity'] }} {{ $item['unit'] }}</td>
                    <td>{{ $item['line_total'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <p class="total">مبلغ نهایی: {{ $payload['totals']['total'] ?? '0' }} ریال</p>
    </section>
@endfor
</body>
</html>
