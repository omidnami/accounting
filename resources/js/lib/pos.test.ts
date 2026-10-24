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
void test('POS totals truncate each step like the Decimal service', () => {
    const result = lineAmounts(
        line({
            quantity: '1.333',
            unit_price: '12.34',
            discount_amount: '0.03',
            tax_rate: '9.5',
        }),
    );
    assert.deepEqual(result, {
        gross: 1644n,
        discount: 3n,
        tax: 155n,
        total: 1796n,
    });
    assert.equal(amountString(result.total), '17.96');
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
