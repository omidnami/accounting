import { normalizeNumber } from './number.ts';

export type PosProduct = {
    id: number;
    code: string;
    barcode: string | null;
    image_url?: string | null;
    name: string;
    unit: string;
    sale_price: string;
    tax_rate: string;
    stock_quantity: string;
    variant_color?: string | null;
    variant_size?: string | null;
};
export type PosLine = PosProduct & {
    quantity: string;
    unit_price: string;
    discount_amount: string;
};
export type PosCustomer = {
    id: number;
    name: string;
    code: string;
    phone: string | null;
    credit_limit: string;
};
export type PosSession = {
    id: number;
    warehouse_id: number;
    cash_account_id: number;
    card_account_id: number | null;
    opening_cash: string;
    expected_cash: string;
    opened_at: string;
    sales_count: number;
};
export type PosSale = {
    id: number;
    invoice_id: number;
    invoice_number: string;
    customer_name: string;
    total: string;
    status: string;
    outstanding: string;
    pending_check: string | number;
};
export type PosTender = {
    method: 'cash' | 'card' | 'check' | 'credit';
    amount: string;
    confirmed: boolean;
    reference: string;
    check_received: boolean;
    check_number: string;
    bank_name: string;
    due_date: string;
    notes: string;
};
export type PendingCheck = {
    id: number;
    amount: string;
    check_received: boolean;
    check_number: string | null;
    bank_name: string | null;
    due_date: string | null;
    notes: string | null;
    invoice_number: string;
    customer_name: string;
    cashier_name: string;
};

export class PosRequestError extends Error {
    fields: Record<string, string[]>;
    constructor(fields: Record<string, string[]>) {
        super(Object.values(fields).flat().join(' · '));
        this.fields = fields;
    }
}

export async function posRequest<T>(
    url: string,
    data?: unknown,
    signal?: AbortSignal,
): Promise<T> {
    const csrf = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const response = await fetch(url, {
        method: data === undefined ? 'GET' : 'POST',
        credentials: 'same-origin',
        signal,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(csrf ? { 'X-XSRF-TOKEN': decodeURIComponent(csrf) } : {}),
        },
        body: data === undefined ? undefined : JSON.stringify(data),
    });
    if (response.status === 422) {
        const result = await response.json();
        throw new PosRequestError(result.errors);
    }
    if (!response.ok) {
        throw new Error(
            response.status === 419 || response.status === 401
                ? 'نشست شما منقضی شده؛ صفحه را تازه‌سازی کنید. فاکتور فعلی در این مرورگر نگهداری می‌شود.'
                : 'ارتباط با سرور کامل نشد؛ وضعیت ثبت را با همان دکمه دوباره بررسی کنید.',
        );
    }
    return response.json() as Promise<T>;
}

// Integer arithmetic mirrors the server's Decimal service: money is stored in
// cents (scale 2) and every step rounds half away from zero, exactly like
// app/Support/Decimal.php. Truncation here would reject valid POS totals.
const fixed = (value: string, scale: number) => {
    const normalized = normalizeNumber(value).trim();
    if (!/^\d*(\.\d*)?$/.test(normalized)) return 0n;
    const [whole = '0', fraction = ''] = normalized.split('.');
    return (
        BigInt(whole || '0') * 10n ** BigInt(scale) +
        BigInt((fraction + '0'.repeat(scale)).slice(0, scale))
    );
};
const roundDiv = (value: bigint, divisor: bigint): bigint => {
    if (value < 0n) return -roundDiv(-value, divisor);
    return (value + divisor / 2n) / divisor;
};
export function lineAmounts(line: PosLine) {
    // quantity (3) x unit price (2) = scale 5, rounded to cents.
    const gross = roundDiv(
        fixed(line.quantity, 3) * fixed(line.unit_price, 2),
        1000n,
    );
    const discount = fixed(line.discount_amount, 2);
    const taxable = gross - discount;
    // taxable (2) x tax rate (2) = scale 4, divided by 100 and rounded to cents.
    const tax = roundDiv(taxable * fixed(line.tax_rate, 2), 10000n);
    return { gross, discount, tax, total: taxable + tax };
}
export const cents = (value: string) => fixed(value, 2);
export const amountString = (value: bigint) => {
    const absolute = value < 0n ? -value : value;
    return `${value < 0n ? '-' : ''}${absolute / 100n}.${(absolute % 100n).toString().padStart(2, '0')}`;
};
