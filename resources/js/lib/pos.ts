import { cents, grossCents, taxCents } from './decimal.ts';

export { amountString, cents } from './decimal.ts';

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
    item_type?: 'stock' | 'non_stock' | 'service';
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
    customer_legacy_current: boolean;
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
    customer_legacy_current: boolean;
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
export function lineAmounts(line: PosLine) {
    const gross = grossCents(line.quantity, line.unit_price);
    const discount = cents(line.discount_amount);
    const taxable = gross - discount;
    const tax = taxCents(taxable, line.tax_rate);
    return { gross, discount, tax, total: taxable + tax };
}
