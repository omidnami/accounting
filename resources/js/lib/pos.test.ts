import assert from 'node:assert/strict';
import test from 'node:test';
import { amountString, cents, lineAmounts, type PosLine } from './pos.ts';

const line = (values: Partial<PosLine>): PosLine => ({
    id: 1,
    code: 'P1',
    barcode: null,
    name: 'کالا',
    unit: 'عدد',
    stock_quantity: '100',
    quantity: '1',
    sale_price: '100',
    unit_price: '100',
    discount_amount: '0',
    tax_rate: '10',
    ...values,
});
void test('POS totals round each step exactly like the Decimal service', () => {
    const result = lineAmounts(
        line({
            quantity: '1.333',
            unit_price: '12.34',
            discount_amount: '0.03',
            tax_rate: '9.5',
        }),
    );
    assert.deepEqual(result, {
        gross: 1645n,
        discount: 3n,
        tax: 156n,
        total: 1798n,
    });
    assert.equal(amountString(result.total), '17.98');
});
void test('POS half-cent boundaries round up, never down', () => {
    // 1 x 0.005 has no cent representation in the price field, so use a
    // quantity that produces exactly half a cent: 0.5 x 0.01 = 0.005.
    const result = lineAmounts(
        line({ quantity: '0.5', unit_price: '0.01', tax_rate: '0' }),
    );
    assert.equal(result.gross, 1n);
    // 1 x 2.5% = 0.025 -> 0.03
    const tax = lineAmounts(
        line({ quantity: '1', unit_price: '1', tax_rate: '2.5' }),
    );
    assert.equal(tax.tax, 3n);
});
void test('Persian amounts and large money stay exact', () => {
    assert.equal(cents('۱٬۲۳۴٫۵۶'), 123456n);
    assert.equal(cents('999999999999.99'), 99999999999999n);
    assert.equal(
        amountString(cents('999999999999.99') + cents('0.01')),
        '1000000000000.00',
    );
});
void test('line discounts cannot silently erase a negative invoice amount', () => {
    assert.ok(lineAmounts(line({ discount_amount: '101' })).total < 0n);
});
