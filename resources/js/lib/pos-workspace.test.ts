import assert from 'node:assert/strict';
import test from 'node:test';
import type { PosLine, PosTender } from './pos.ts';
import {
    MAX_POS_CARTS,
    activatePosCart,
    addPosCart,
    closePosCart,
    completePosCart,
    newPosWorkspace,
    pendingPosCart,
    resetPosCart,
    restoreArchivedCart,
    restorePosWorkspace,
} from './pos-workspace.ts';
const line = (price = '100'): PosLine => ({
    id: 1,
    code: 'P1',
    barcode: null,
    name: 'کالا',
    unit: 'عدد',
    stock_quantity: '100',
    quantity: '1',
    sale_price: '100',
    unit_price: price,
    discount_amount: '0',
    tax_rate: '0',
});
const tender = (number: string): PosTender => ({
    method: 'check',
    amount: '100',
    confirmed: false,
    reference: '',
    check_received: true,
    check_number: number,
    bank_name: 'بانک',
    due_date: '2026-11-01',
    notes: '',
});
void test('five open carts have independent identities, lines, customers and payment drafts', () => {
    const workspace = newPosWorkspace(crypto.randomUUID());
    const first = workspace.tabs[0];
    first.lines.push(line());
    first.customer = {
        id: 1,
        name: 'اول',
        code: 'C1',
        phone: null,
        credit_limit: '10000',
    };
    first.payment = {
        mode: 'check',
        dueDate: '',
        total: '100',
        rows: [tender('CHECK-1')],
    };
    const second = addPosCart(workspace)!;
    second.lines.push(line('200'));
    second.customer = {
        id: 2,
        name: 'دوم',
        code: 'C2',
        phone: null,
        credit_limit: '20000',
    };
    second.payment.rows.push(tender('CHECK-2'));
    second.lines[0].quantity = '4';
    assert.equal(first.lines[0].quantity, '1');
    assert.equal(first.lines[0].unit_price, '100');
    assert.equal(first.customer.id, 1);
    assert.equal(first.payment.rows[0].check_number, 'CHECK-1');
    assert.notEqual(first.token, second.token);
    assert.notEqual(first.id, second.id);
    while (workspace.tabs.length < MAX_POS_CARTS)
        assert.ok(addPosCart(workspace));
    assert.equal(addPosCart(workspace), null);
    assert.equal(workspace.tabs.length, 5);
    assert.equal(new Set(workspace.tabs.map((cart) => cart.token)).size, 5);
});
void test('an uncertain checkout locks switching, adding, clearing and closing carts', () => {
    const workspace = newPosWorkspace(crypto.randomUUID());
    const first = workspace.tabs[0];
    first.lines.push(line());
    const second = addPosCart(workspace)!;
    first.pending = {
        submission_token: first.token,
        items: [{ product_id: 1 }],
    };
    assert.equal(pendingPosCart(workspace)?.id, first.id);
    assert.equal(activatePosCart(workspace, first.id), false);
    assert.equal(workspace.activeId, second.id);
    assert.equal(addPosCart(workspace), null);
    assert.equal(closePosCart(workspace, second.id), false);
    resetPosCart(first);
    assert.equal(first.lines.length, 1);
    assert.ok(first.pending);
});
void test('a successful response only removes its original cart and token, including a nonactive cart', () => {
    const workspace = newPosWorkspace(crypto.randomUUID());
    const first = workspace.tabs[0];
    first.lines.push(line());
    const second = addPosCart(workspace)!;
    second.lines.push(line('200'));
    first.pending = { submission_token: first.token };
    assert.equal(completePosCart(workspace, first.id, second.token), false);
    assert.equal(completePosCart(workspace, second.id, first.token), false);
    assert.equal(workspace.tabs.length, 2);
    assert.equal(completePosCart(workspace, first.id, first.token), true);
    assert.equal(workspace.activeId, second.id);
    assert.equal(workspace.tabs[0].lines[0].unit_price, '200');
    assert.equal(completePosCart(workspace, first.id, first.token), false);
    assert.equal(workspace.tabs.length, 1);
});
void test('closing a cart keeps the adjacent cart intact and new cart numbers never reuse an open number', () => {
    const workspace = newPosWorkspace(crypto.randomUUID());
    const first = workspace.tabs[0];
    const second = addPosCart(workspace)!;
    second.lines.push(line());
    closePosCart(workspace, first.id);
    assert.equal(workspace.activeId, second.id);
    assert.equal(addPosCart(workspace)!.number, 3);
    closePosCart(workspace, workspace.activeId);
    closePosCart(workspace, second.id);
    assert.equal(workspace.tabs.length, 1);
    assert.equal(workspace.tabs[0].number, 4);
    assert.equal(workspace.tabs[0].lines.length, 0);
});
void test('reload restores the active tab and each payment draft without sharing objects', () => {
    const workspace = newPosWorkspace(crypto.randomUUID());
    workspace.tabs[0].lines.push(line());
    const second = addPosCart(workspace)!;
    second.lines.push(line('999'));
    second.notes = 'دوم';
    second.payment = {
        mode: 'mixed',
        total: '999',
        dueDate: '',
        rows: [tender('SECOND-CHECK')],
    };
    const saved = JSON.parse(JSON.stringify({ workspace }));
    const restored = restorePosWorkspace(saved, crypto.randomUUID());
    assert.deepEqual(restored, workspace);
    saved.workspace.tabs[0].lines[0].unit_price = 'WRONG';
    restored.tabs[1].payment.rows[0].check_number = 'CHANGED';
    assert.equal(restored.tabs[0].lines[0].unit_price, '100');
    assert.equal(
        workspace.tabs[1].payment.rows[0].check_number,
        'SECOND-CHECK',
    );
});
void test('legacy held carts migrate without dropping overflow or the original pending submission', () => {
    const token = crypto.randomUUID();
    const restored = restorePosWorkspace(
        {
            lines: [line()],
            token,
            notes: 'اول',
            customer: null,
            pending: { submission_token: token, session_id: 1 },
            held: Array.from({ length: 8 }, (_, index) => ({
                id: crypto.randomUUID(),
                lines: [line(String(index + 200))],
                notes: `held-${index}`,
                customer: null,
            })),
        },
        crypto.randomUUID(),
    );
    assert.equal(restored.tabs.length, 5);
    assert.equal(restored.archived.length, 4);
    assert.equal(pendingPosCart(restored)?.token, token);
    assert.equal(completePosCart(restored, restored.tabs[0].id, token), true);
    assert.deepEqual(
        restorePosWorkspace({ workspace: restored }, crypto.randomUUID()),
        restored,
    );
    assert.equal(restoreArchivedCart(restored, restored.archived[0].id), true);
    assert.equal(restored.tabs.length, 5);
    assert.equal(restored.archived.length, 3);
    const reloaded = restorePosWorkspace(
        { workspace: restored },
        crypto.randomUUID(),
    );
    assert.deepEqual(reloaded, restored);
});
void test('restoring duplicate identities cannot make two tabs share an id or a new submission token', () => {
    const workspace = newPosWorkspace(crypto.randomUUID());
    workspace.tabs.push(JSON.parse(JSON.stringify(workspace.tabs[0])));
    const restored = restorePosWorkspace({ workspace }, crypto.randomUUID());
    assert.notEqual(restored.tabs[0].id, restored.tabs[1].id);
    assert.notEqual(restored.tabs[0].token, restored.tabs[1].token);
    assert.notEqual(restored.tabs[0].number, restored.tabs[1].number);
    const empty = restorePosWorkspace(
        { workspace: { version: 2, tabs: [null, { lines: false }] } },
        crypto.randomUUID(),
    );
    assert.equal(empty.tabs.length, 1);
});
