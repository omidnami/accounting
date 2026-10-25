import type { PosCustomer, PosLine, PosTender } from './pos.ts';

export const MAX_POS_CARTS = 5;
export type PosPaymentDraft = {
    mode: PosTender['method'] | 'mixed';
    dueDate: string;
    total: string;
    rows: PosTender[];
};
export type PosCart = {
    id: string;
    number: number;
    token: string;
    lines: PosLine[];
    customer: PosCustomer | null;
    notes: string;
    payment: PosPaymentDraft;
    pending: Record<string, unknown> | null;
};
export type PosWorkspace = {
    version: 2;
    activeId: string;
    nextNumber: number;
    tabs: PosCart[];
    archived: PosCart[];
};
export const emptyPayment = (): PosPaymentDraft => ({
    mode: 'cash',
    dueDate: '',
    total: '',
    rows: [],
});
export function newPosCart(
    number: number,
    token: string = crypto.randomUUID(),
): PosCart {
    return {
        id: token,
        number,
        token,
        lines: [],
        customer: null,
        notes: '',
        payment: emptyPayment(),
        pending: null,
    };
}
export function newPosWorkspace(token: string): PosWorkspace {
    const cart = newPosCart(1, token);
    return {
        version: 2,
        activeId: cart.id,
        nextNumber: 2,
        tabs: [cart],
        archived: [],
    };
}
export const pendingPosCart = (workspace: PosWorkspace) =>
    [...workspace.tabs, ...workspace.archived].find(
        (cart) => cart.pending !== null,
    );
export function addPosCart(workspace: PosWorkspace): PosCart | null {
    if (workspace.tabs.length >= MAX_POS_CARTS || pendingPosCart(workspace))
        return null;
    const cart = newPosCart(workspace.nextNumber++);
    workspace.tabs.push(cart);
    workspace.activeId = cart.id;
    return cart;
}
export function activatePosCart(workspace: PosWorkspace, id: string): boolean {
    if (
        pendingPosCart(workspace) ||
        !workspace.tabs.some((cart) => cart.id === id)
    )
        return false;
    workspace.activeId = id;
    return true;
}
export function resetPosCart(cart: PosCart): void {
    if (cart.pending) return;
    Object.assign(cart, {
        lines: [],
        customer: null,
        notes: '',
        token: crypto.randomUUID(),
        payment: emptyPayment(),
    });
}
function removeCart(workspace: PosWorkspace, id: string): void {
    const index = workspace.tabs.findIndex((cart) => cart.id === id);
    workspace.tabs = workspace.tabs.filter((cart) => cart.id !== id);
    workspace.archived = workspace.archived.filter((cart) => cart.id !== id);
    if (!workspace.tabs.length)
        workspace.tabs.push(newPosCart(workspace.nextNumber++));
    if (!workspace.tabs.some((cart) => cart.id === workspace.activeId)) {
        workspace.activeId =
            workspace.tabs[
                Math.max(0, Math.min(index, workspace.tabs.length - 1))
            ].id;
    }
}
export function closePosCart(workspace: PosWorkspace, id: string): boolean {
    if (workspace.tabs.length <= 1 || pendingPosCart(workspace)) return false;
    removeCart(workspace, id);
    return true;
}
export function completePosCart(
    workspace: PosWorkspace,
    id: string,
    token: string,
): boolean {
    const cart = [...workspace.tabs, ...workspace.archived].find(
        (item) => item.id === id,
    );
    if (
        !cart ||
        cart.token !== token ||
        cart.pending?.submission_token !== token
    )
        return false;
    removeCart(workspace, id);
    return true;
}
export function restoreArchivedCart(
    workspace: PosWorkspace,
    id: string,
): boolean {
    if (workspace.tabs.length >= MAX_POS_CARTS || pendingPosCart(workspace))
        return false;
    const cart = workspace.archived.find((item) => item.id === id);
    if (!cart) return false;
    workspace.archived = workspace.archived.filter((item) => item.id !== id);
    workspace.tabs.push(cart);
    workspace.activeId = cart.id;
    return true;
}
const record = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null && !Array.isArray(value);
const text = (value: unknown) => (typeof value === 'string' ? value : '');
function readCart(raw: unknown, number: number): PosCart | null {
    if (!record(raw) || !Array.isArray(raw.lines)) return null;
    const cart = newPosCart(
        typeof raw.number === 'number' &&
            Number.isSafeInteger(raw.number) &&
            raw.number > 0
            ? raw.number
            : number,
        text(raw.token) || crypto.randomUUID(),
    );
    cart.id = text(raw.id) || cart.id;
    cart.lines = raw.lines.filter(
        (line): line is PosLine =>
            record(line) &&
            typeof line.id === 'number' &&
            typeof line.name === 'string' &&
            [
                'code',
                'quantity',
                'unit_price',
                'discount_amount',
                'tax_rate',
                'stock_quantity',
                'sale_price',
                'unit',
            ].every((key) => typeof line[key] === 'string'),
    );
    if (
        record(raw.customer) &&
        typeof raw.customer.id === 'number' &&
        typeof raw.customer.name === 'string'
    ) {
        cart.customer = raw.customer as PosCustomer;
    }
    cart.notes = text(raw.notes);
    if (record(raw.pending)) {
        cart.pending = raw.pending;
        cart.token = text(raw.pending.submission_token) || cart.token;
    }
    if (
        record(raw.payment) &&
        ['cash', 'card', 'check', 'credit', 'mixed'].includes(
            text(raw.payment.mode),
        )
    ) {
        cart.payment = {
            mode: raw.payment.mode as PosPaymentDraft['mode'],
            dueDate: text(raw.payment.dueDate),
            total: text(raw.payment.total),
            rows: Array.isArray(raw.payment.rows)
                ? raw.payment.rows.filter(
                      (row): row is PosTender =>
                          record(row) &&
                          ['cash', 'card', 'check', 'credit'].includes(
                              text(row.method),
                          ) &&
                          [
                              'amount',
                              'reference',
                              'check_number',
                              'bank_name',
                              'due_date',
                              'notes',
                          ].every((key) => typeof row[key] === 'string') &&
                          typeof row.confirmed === 'boolean' &&
                          typeof row.check_received === 'boolean',
                  )
                : [],
        };
    }
    // Never retain shared references between carts or the parsed storage snapshot.
    return JSON.parse(JSON.stringify(cart)) as PosCart;
}
export function restorePosWorkspace(
    saved: unknown,
    fallbackToken: string,
): PosWorkspace {
    if (!record(saved)) return newPosWorkspace(fallbackToken);
    const raw =
        record(saved.workspace) && saved.workspace.version === 2
            ? saved.workspace
            : null;
    const source = raw
        ? [
              ...(Array.isArray(raw.tabs) ? raw.tabs : []),
              ...(Array.isArray(raw.archived) ? raw.archived : []),
          ]
        : [saved, ...(Array.isArray(saved.held) ? saved.held : [])];
    const archivedCarts = new Set<PosCart>();
    const originalTabCount =
        raw && Array.isArray(raw.tabs) ? raw.tabs.length : 0;
    const carts = source
        .map((item, index) => {
            const cart = readCart(item, index + 1);
            if (raw && index >= originalTabCount && cart)
                archivedCarts.add(cart);
            return cart;
        })
        .filter((cart): cart is PosCart => cart !== null);
    if (!carts.length) return newPosWorkspace(fallbackToken);
    const ids = new Set<string>();
    const tokens = new Set<string>();
    const numbers = new Set<number>();
    carts.forEach((cart) => {
        if (ids.has(cart.id)) cart.id = crypto.randomUUID();
        if (tokens.has(cart.token) && !cart.pending)
            cart.token = crypto.randomUUID();
        while (numbers.has(cart.number)) cart.number++;
        ids.add(cart.id);
        tokens.add(cart.token);
        numbers.add(cart.number);
    });
    let nextNumber =
        Math.max(
            ...carts.map((cart) => cart.number),
            typeof raw?.nextNumber === 'number' &&
                Number.isSafeInteger(raw.nextNumber)
                ? raw.nextNumber - 1
                : 0,
        ) + 1;
    const tabs = carts
        .filter((cart) => !archivedCarts.has(cart))
        .slice(0, MAX_POS_CARTS);
    const archived = carts.filter((cart) => !tabs.includes(cart));
    if (!tabs.length) tabs.push(newPosCart(nextNumber++));
    const activeId = tabs.some((cart) => cart.id === raw?.activeId)
        ? String(raw?.activeId)
        : tabs[0].id;
    return {
        version: 2,
        activeId,
        nextNumber,
        tabs,
        archived,
    };
}
