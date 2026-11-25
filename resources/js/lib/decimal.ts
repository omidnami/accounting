import { normalizeNumber } from './number.ts';

export const fixed = (value: string, scale: number): bigint => {
    const normalized = normalizeNumber(value).trim();
    if (!/^\d*(\.\d*)?$/.test(normalized)) return 0n;
    const [whole = '0', fraction = ''] = normalized.split('.');
    return (
        BigInt(whole || '0') * 10n ** BigInt(scale) +
        BigInt((fraction + '0'.repeat(scale)).slice(0, scale))
    );
};

export const roundDiv = (value: bigint, divisor: bigint): bigint => {
    if (value < 0n) return -roundDiv(-value, divisor);
    return (value + divisor / 2n) / divisor;
};

export const grossCents = (quantity: string, unitPrice: string): bigint =>
    roundDiv(fixed(quantity, 3) * fixed(unitPrice, 2), 1000n);

export const taxCents = (taxableCents: bigint, taxRate: string): bigint =>
    roundDiv(taxableCents * fixed(taxRate, 2), 10000n);

export const cents = (value: string): bigint => fixed(value, 2);

export const amountString = (value: bigint): string => {
    const absolute = value < 0n ? -value : value;
    return `${value < 0n ? '-' : ''}${absolute / 100n}.${(absolute % 100n).toString().padStart(2, '0')}`;
};

export const amountNumber = (value: bigint): number =>
    Number(amountString(value));
