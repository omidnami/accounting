export type InvoicePrintFormat = 'thermal_58' | 'thermal_80' | 'a5' | 'a4';

export type InvoicePrintFormatOption = {
    value: InvoicePrintFormat;
    label: string;
    description: string;
    kind: 'thermal' | 'page';
    paperWidth?: 58 | 80;
};

export const invoicePrintFormats: InvoicePrintFormatOption[] = [
    {
        value: 'thermal_80',
        label: 'حرارتی ۸۰ میلی‌متر',
        description: 'استاندارد صندوق و خواناتر برای فاکتور روزانه',
        kind: 'thermal',
        paperWidth: 80,
    },
    {
        value: 'thermal_58',
        label: 'حرارتی ۵۸ میلی‌متر',
        description: 'فشرده و مناسب چاپگرهای کوچک',
        kind: 'thermal',
        paperWidth: 58,
    },
    {
        value: 'a5',
        label: 'A5 عمودی',
        description: 'فاکتور رسمی نیم‌برگ',
        kind: 'page',
    },
    {
        value: 'a4',
        label: 'A4 عمودی',
        description: 'فاکتور رسمی و بایگانی اداری',
        kind: 'page',
    },
];

export const invoicePrintFormatMap = Object.fromEntries(
    invoicePrintFormats.map((format) => [format.value, format]),
) as Record<InvoicePrintFormat, InvoicePrintFormatOption>;
