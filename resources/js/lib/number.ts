const persianDigits = '۰۱۲۳۴۵۶۷۸۹';
const arabicDigits = '٠١٢٣٤٥٦٧٨٩';

export const normalizeNumber = (value: string): string =>
    value
        .replace(/[۰-۹]/g, (digit) => `${persianDigits.indexOf(digit)}`)
        .replace(/[٠-٩]/g, (digit) => `${arabicDigits.indexOf(digit)}`)
        .replaceAll('٫', '.')
        .replace(/[٬,،\s]/g, '');

export const parseNumber = (value: string | number): number => {
    const parsed = Number(normalizeNumber(String(value)));

    return Number.isFinite(parsed) ? parsed : 0;
};
