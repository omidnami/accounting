<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowUpLeft,
    Barcode,
    Check,
    ChevronLeft,
    Clock3,
    CreditCard,
    Expand,
    LayoutGrid,
    LogOut,
    Minus,
    Monitor,
    Package,
    Plus,
    Printer,
    ReceiptText,
    Search,
    ShoppingBasket,
    Trash2,
    UserRound,
    Wallet,
    X,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
    type Ref,
} from 'vue';
import PosCheckoutDialog from '@/components/pos/PosCheckoutDialog.vue';
import PosCheckReview from '@/components/pos/PosCheckReview.vue';
import PosViewportNotice from '@/components/pos/PosViewportNotice.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { usePosExitGuard } from '@/composables/usePosExitGuard';
import { normalizeNumber, parseNumber } from '@/lib/number';
import {
    amountString,
    lineAmounts,
    PosRequestError,
    posRequest,
    type PendingCheck,
    type PosCustomer,
    type PosLine,
    type PosProduct,
    type PosSale,
    type PosSession,
    type PosTender,
} from '@/lib/pos';
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
    type PosCart,
} from '@/lib/pos-workspace';
import { dashboard } from '@/routes';
import * as posRoutes from '@/routes/pos';
import { close as closeShift } from '@/routes/pos';
import { customers as customersUrl } from '@/routes/sales/lookups';
import { show as saleUrl } from '@/routes/sales';
import '@/../css/pos.css';

const props = defineProps<{
    store_name: string;
    today: string;
    session: PosSession | null;
    submission_token: string;
    fiscal_year: string | null;
    can_review: boolean;
    warehouses: { id: number; name: string }[];
    treasury_accounts: { id: number; name: string; type: 'cash' | 'card' }[];
    categories: { id: number; name: string }[];
}>();
const user = usePage().props.auth.user;
const compactViewport =
    typeof window === 'undefined'
        ? null
        : window.matchMedia('(width < 1000px)');
const viewportTooNarrow = ref(compactViewport?.matches ?? false);
function updateViewport(event: MediaQueryListEvent) {
    viewportTooNarrow.value = event.matches;
}
// Hide dialogs while blocked without losing their draft inputs or open state.
function viewportDialog(open: Ref<boolean>) {
    return computed({
        get: () => open.value && !viewportTooNarrow.value,
        set: (value: boolean) => {
            if (!viewportTooNarrow.value) open.value = value;
        },
    });
}
const session = ref<PosSession | null>(props.session);
const workspace = ref(newPosWorkspace(props.submission_token));
const workspaceReady = ref(false);
const activeCart = computed(
    () =>
        workspace.value.tabs.find(
            (cart) => cart.id === workspace.value.activeId,
        ) ?? workspace.value.tabs[0],
);
function cartField<K extends keyof PosCart>(key: K) {
    return computed({
        get: () => activeCart.value[key],
        set: (value: PosCart[K]) => {
            activeCart.value[key] = value;
        },
    });
}
const lines = cartField('lines');
const selectedCustomer = cartField('customer');
const notes = cartField('notes');
const paymentDraft = cartField('payment');
const pendingCart = computed(() => pendingPosCart(workspace.value));
const error = ref('');
const notice = ref('');
const busy = ref(false);
const pendingPayload = computed(() => pendingCart.value?.pending ?? null);
const tabsLocked = computed(
    () => !workspaceReady.value || busy.value || Boolean(pendingCart.value),
);
const paymentOpen = ref(false);
const customerOpen = ref(false);
const activityOpen = ref(false);
const checksOpen = ref(false);
const shiftOpen = ref(false);
const lastSale = ref<PosSale | null>(null);
const successOpen = ref(false);
const recentSales = ref<PosSale[]>([]);
const pendingChecks = ref<PendingCheck[]>([]);
const search = ref('');
const category = ref<number | null>(null);
const products = ref<PosProduct[]>([]);
const failedImages = ref(new Set<string>());
const productLoading = ref(false);
const searchInput = ref<HTMLInputElement | null>(null);
const customerSearch = ref('');
const customerResults = ref<PosCustomer[]>([]);
const customerLoading = ref(false);
const now = ref(new Date());
const held = computed(() => workspace.value.archived);
const heldOpen = ref(false);
const customerDialog = viewportDialog(customerOpen);
const activityDialog = viewportDialog(activityOpen);
const shiftDialog = viewportDialog(shiftOpen);
const heldDialog = viewportDialog(heldOpen);
const successDialog = viewportDialog(successOpen);

const storageKey = `pos-draft:${user.id}`;
const warehouseId = ref(props.warehouses[0]?.id.toString() || '');
const cashId = ref(
    props.treasury_accounts
        .find((account) => account.type === 'cash')
        ?.id.toString() || '',
);
const cardId = ref(
    props.treasury_accounts
        .find((account) => account.type === 'card')
        ?.id.toString() || '',
);
const openingCash = ref('0');
const countedCash = ref('');
const closingNotes = ref('');
let productTimer: ReturnType<typeof setTimeout> | undefined;
let customerTimer: ReturnType<typeof setTimeout> | undefined;
let clockTimer: ReturnType<typeof setInterval> | undefined;
let productAbort: AbortController | undefined;
let customerAbort: AbortController | undefined;
let restoring = false;
let disposed = false;
const money = (value: number | string) =>
    new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 2 }).format(
        Number(value),
    );
const qty = (value: string | number) =>
    new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 3 }).format(
        Number(value),
    );
const timeLabel = computed(() =>
    now.value.toLocaleTimeString('fa-IR', {
        hour: '2-digit',
        minute: '2-digit',
    }),
);
const dateLabel = computed(() =>
    now.value.toLocaleDateString('fa-IR', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }),
);
const totalCents = computed(() =>
    lines.value.reduce((sum, line) => sum + lineAmounts(line).total, 0n),
);
const total = computed(() => amountString(totalCents.value));
const subtotal = computed(() =>
    amountString(
        lines.value.reduce((sum, line) => sum + lineAmounts(line).gross, 0n),
    ),
);
const tax = computed(() =>
    amountString(
        lines.value.reduce((sum, line) => sum + lineAmounts(line).tax, 0n),
    ),
);
const discount = computed(() =>
    amountString(
        lines.value.reduce((sum, line) => sum + lineAmounts(line).discount, 0n),
    ),
);
const itemCount = computed(() =>
    lines.value.reduce((sum, line) => sum + parseNumber(line.quantity), 0),
);
const warehouseName = computed(
    () =>
        props.warehouses.find(
            (warehouse) => warehouse.id === session.value?.warehouse_id,
        )?.name || 'انبار فروش',
);
const invalidLines = computed(() =>
    lines.value.some(
        (line) =>
            parseNumber(line.quantity) <= 0 ||
            parseNumber(line.quantity) > parseNumber(line.stock_quantity) ||
            parseNumber(line.unit_price) <= 0 ||
            parseNumber(line.discount_amount) < 0 ||
            lineAmounts(line).total < 0n,
    ),
);
const difference = computed(
    () =>
        parseNumber(countedCash.value) -
        parseNumber(session.value?.expected_cash || '0'),
);
usePosExitGuard(() => busy.value);

function saveDraft() {
    if (restoring) return;
    try {
        localStorage.setItem(
            storageKey,
            JSON.stringify({
                session_id: session.value?.id,
                workspace: workspace.value,
            }),
        );
    } catch {
        /* The form remains usable when browser storage is unavailable. */
    }
}
watch([workspace], saveDraft, { deep: true });

async function loadProducts() {
    if (!session.value) return;
    productAbort?.abort();
    productAbort = new AbortController();
    const controller = productAbort;
    productLoading.value = true;
    try {
        const result = await posRequest<{ data: PosProduct[] }>(
            posRoutes.products.url({
                query: {
                    search: search.value,
                    category_id: category.value ?? undefined,
                },
            }),
            undefined,
            controller.signal,
        );
        products.value = result.data;
    } catch (exception) {
        if (
            !(
                exception instanceof DOMException &&
                exception.name === 'AbortError'
            )
        )
            showError(exception);
    } finally {
        if (!controller.signal.aborted) productLoading.value = false;
    }
}
watch([search, category], () => {
    clearTimeout(productTimer);
    productAbort?.abort();
    productTimer = setTimeout(() => void loadProducts(), 180);
});
async function scan() {
    const cartId = activeCart.value.id;
    const barcode = normalizeNumber(search.value).trim();
    await loadProducts();
    if (cartId !== activeCart.value.id || tabsLocked.value) return;
    const product = products.value.find(
        (item) => item.barcode === barcode || item.code === barcode,
    );
    if (product) {
        addProduct(product);
        search.value = '';
    } else notice.value = 'کالای موردنظر را از نتایج انتخاب کنید.';
}
function addProduct(product: PosProduct) {
    if (tabsLocked.value || !session.value) return;
    const existing = lines.value.find((line) => line.id === product.id);
    const next = existing ? parseNumber(existing.quantity) + 1 : 1;
    if (next > parseNumber(product.stock_quantity)) {
        error.value = 'موجودی این کالا برای مقدار انتخابی کافی نیست.';
        return;
    }
    error.value = '';
    notice.value = '';
    if (existing) {
        existing.quantity = String(next);
        existing.stock_quantity = product.stock_quantity;
    } else
        lines.value.push({
            ...product,
            quantity: '1',
            unit_price: product.sale_price,
            discount_amount: '0',
        });
}
function changeQuantity(line: PosLine, delta: number) {
    const next = Math.round((parseNumber(line.quantity) + delta) * 1000) / 1000;
    if (next <= 0) return;
    if (next > parseNumber(line.stock_quantity)) {
        error.value = 'موجودی کالا کافی نیست.';
        return;
    }
    line.quantity = String(next);
}
function clearCart() {
    if (
        tabsLocked.value ||
        (lines.value.length && !window.confirm('فاکتور در حال ثبت پاک شود؟'))
    )
        return;
    resetPosCart(activeCart.value);
    error.value = '';
    notice.value = '';
}
function focusTab() {
    void nextTick(() =>
        document
            .getElementById(`pos-tab-${workspace.value.activeId}`)
            ?.scrollIntoView({ block: 'nearest', inline: 'nearest' }),
    );
}
function switchTab(id: string) {
    if (tabsLocked.value || !activatePosCart(workspace.value, id)) return;
    paymentOpen.value = false;
    customerOpen.value = false;
    error.value = '';
    notice.value = '';
    focusTab();
}
function addTab() {
    if (tabsLocked.value || !session.value) return;
    if (!addPosCart(workspace.value)) {
        notice.value = 'حداکثر ۵ فاکتور باز؛ ابتدا یکی را ثبت کنید یا ببندید.';
        return;
    }
    error.value = '';
    notice.value = '';
    focusTab();
}
function closeTab(id: string) {
    if (tabsLocked.value || workspace.value.tabs.length <= 1) return;
    const cart = workspace.value.tabs.find((item) => item.id === id);
    if (!cart) return;
    if (
        (cart.lines.length ||
            cart.customer ||
            cart.notes ||
            cart.payment.rows.length) &&
        !window.confirm(
            `فاکتور باز ${qty(cart.number)} بسته و اطلاعات آن پاک شود؟`,
        )
    )
        return;
    closePosCart(workspace.value, id);
    error.value = '';
    notice.value = '';
    focusTab();
}
function tabKeydown(event: KeyboardEvent) {
    const index = workspace.value.tabs.findIndex(
        (cart) => cart.id === workspace.value.activeId,
    );
    const count = workspace.value.tabs.length;
    const next =
        event.key === 'ArrowLeft'
            ? (index + 1) % count
            : event.key === 'ArrowRight'
              ? (index - 1 + count) % count
              : event.key === 'Home'
                ? 0
                : event.key === 'End'
                  ? count - 1
                  : null;
    if (next === null || tabsLocked.value) return;
    event.preventDefault();
    switchTab(workspace.value.tabs[next].id);
    void nextTick(() =>
        document.getElementById(`pos-tab-${workspace.value.activeId}`)?.focus(),
    );
}
function restoreHeld(id: string) {
    if (tabsLocked.value) return;
    if (!restoreArchivedCart(workspace.value, id)) {
        error.value = 'برای بازکردن این فاکتور، ابتدا یکی از ۵ تب را ببندید.';
        return;
    }
    heldOpen.value = false;
    error.value = '';
    focusTab();
}
function removeHeld(id: string) {
    if (!tabsLocked.value && window.confirm('این فاکتور در انتظار حذف شود؟')) {
        workspace.value.archived = workspace.value.archived.filter(
            (cart) => cart.id !== id,
        );
    }
}
function showError(exception: unknown) {
    error.value =
        exception instanceof Error ? exception.message : 'عملیات کامل نشد.';
}
async function loadCustomers() {
    customerAbort?.abort();
    customerAbort = new AbortController();
    const controller = customerAbort;
    customerLoading.value = true;
    try {
        const result = await posRequest<{ data: PosCustomer[] }>(
            customersUrl.url({ query: { search: customerSearch.value } }),
            undefined,
            controller.signal,
        );
        customerResults.value = result.data.filter(
            (customer) => customer.code !== 'POS-CASH',
        );
    } catch (exception) {
        if (
            !(
                exception instanceof DOMException &&
                exception.name === 'AbortError'
            )
        )
            showError(exception);
    } finally {
        if (!controller.signal.aborted) customerLoading.value = false;
    }
}
watch(customerSearch, () => {
    clearTimeout(customerTimer);
    customerAbort?.abort();
    customerTimer = setTimeout(() => void loadCustomers(), 180);
});
watch(customerOpen, (open) => {
    if (open) void loadCustomers();
});
async function refreshActivity() {
    try {
        const result = await posRequest<{
            session: PosSession | null;
            recent_sales: PosSale[];
            pending_checks: PendingCheck[];
        }>(posRoutes.activity.url());
        session.value = result.session;
        recentSales.value = result.recent_sales;
        pendingChecks.value = result.pending_checks;
    } catch (exception) {
        showError(exception);
    }
}
async function submitShift() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        if (session.value) {
            if (workspace.value.tabs.some((cart) => cart.lines.length)) {
                error.value = 'ابتدا فاکتورهای باز را ثبت کنید یا ببندید.';
                return;
            }
            await posRequest(closeShift.url(session.value.id), {
                counted_cash: normalizeNumber(countedCash.value),
                notes: closingNotes.value,
            });
            session.value = null;
            products.value = [];
            shiftOpen.value = false;
            notice.value =
                'شیفت بسته شد. مغایرت احتمالی برای بررسی حسابدار ثبت شده است.';
        } else {
            const result = await posRequest<{ session: PosSession }>(
                posRoutes.open.url(),
                {
                    warehouse_id: warehouseId.value,
                    cash_account_id: cashId.value,
                    card_account_id: cardId.value || null,
                    opening_cash: normalizeNumber(openingCash.value),
                },
            );
            session.value = result.session;
            shiftOpen.value = false;
            saveDraft();
            await loadProducts();
        }
        await refreshActivity();
    } catch (exception) {
        showError(exception);
    } finally {
        busy.value = false;
    }
}
async function showShift() {
    error.value = '';
    await refreshActivity();
    countedCash.value = session.value?.expected_cash || '';
    shiftOpen.value = true;
}
async function checkout(tenders: PosTender[], dueDate: string) {
    if (!session.value || tabsLocked.value) return;
    activeCart.value.pending = {
        session_id: session.value.id,
        submission_token: activeCart.value.token,
        customer_id: selectedCustomer.value?.id || null,
        due_date: dueDate || null,
        notes: notes.value || null,
        items: lines.value.map((line) => ({
            product_id: line.id,
            quantity: normalizeNumber(line.quantity),
            unit_price: normalizeNumber(line.unit_price),
            discount_amount: normalizeNumber(line.discount_amount),
            tax_rate: line.tax_rate,
        })),
        tenders,
    };
    saveDraft();
    await retryCheckout();
}
async function retryCheckout() {
    const cart = pendingCart.value;
    const payload = cart?.pending;
    if (!cart || !payload || busy.value) return;
    const submittedToken = String(payload.submission_token);
    busy.value = true;
    error.value = '';
    try {
        // Recover a committed sale before validating/retrying an old payload.
        const status = await posRequest<{ sale: PosSale | null }>(
            posRoutes.attempt.url({
                query: { token: submittedToken },
            }),
        );
        const result = status.sale
            ? { sale: status.sale }
            : await posRequest<{ sale: PosSale }>(
                  posRoutes.checkout.url(),
                  payload,
              );
        if (!completePosCart(workspace.value, cart.id, submittedToken))
            throw new Error(
                'فاکتور ثبت‌شده با تب در انتظار تطبیق ندارد؛ نتیجه را بررسی کنید.',
            );
        lastSale.value = result.sale;
        saveDraft();
        paymentOpen.value = false;
        shiftOpen.value = false;
        successOpen.value = true;
        await Promise.all([refreshActivity(), loadProducts()]);
    } catch (exception) {
        if (exception instanceof PosRequestError) cart.pending = null;
        else paymentOpen.value = false;
        saveDraft();
        showError(exception);
    } finally {
        busy.value = false;
    }
}
function openPayment() {
    if (lines.value.length && !invalidLines.value && !tabsLocked.value) {
        error.value = '';
        paymentOpen.value = true;
    }
}
function fullscreen() {
    if (document.fullscreenElement)
        void document.exitFullscreen().catch(() => {});
    else void document.documentElement.requestFullscreen?.().catch(() => {});
}
function hotkeys(event: KeyboardEvent) {
    if (
        viewportTooNarrow.value ||
        tabsLocked.value ||
        shiftOpen.value ||
        paymentOpen.value ||
        customerOpen.value ||
        checksOpen.value ||
        activityOpen.value ||
        heldOpen.value ||
        successOpen.value
    )
        return;
    if (event.key === 'F2') {
        event.preventDefault();
        searchInput.value?.focus();
    }
    if (event.key === 'F4') {
        event.preventDefault();
        customerOpen.value = true;
    }
    if (event.key === 'F8') {
        event.preventDefault();
        openPayment();
    }
}
onMounted(async () => {
    compactViewport?.addEventListener('change', updateViewport);
    restoring = true;
    // History may restore old Inertia props from before this shift was opened.
    // Compare the saved cart with the live shift before restoring its data.
    await refreshActivity();
    if (disposed) return;
    shiftOpen.value = !session.value;
    try {
        const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
        if (saved)
            workspace.value = restorePosWorkspace(
                saved,
                props.submission_token,
            );
    } catch {
        /* Invalid or unavailable browser storage does not block POS. */
    }
    restoring = false;
    workspaceReady.value = true;
    if (pendingPayload.value) await retryCheckout();
    if (disposed) return;
    clockTimer = setInterval(() => {
        now.value = new Date();
    }, 1000);
    window.addEventListener('keydown', hotkeys);
    await loadProducts();
    await nextTick();
    if (!viewportTooNarrow.value) searchInput.value?.focus();
});
onBeforeUnmount(() => {
    compactViewport?.removeEventListener('change', updateViewport);
    disposed = true;
    clearTimeout(productTimer);
    clearTimeout(customerTimer);
    clearInterval(clockTimer);
    productAbort?.abort();
    customerAbort?.abort();
    window.removeEventListener('keydown', hotkeys);
});
</script>

<template>
    <Head title="صندوق فروش" />
    <div
        class="pos-screen"
        dir="rtl"
        :inert="viewportTooNarrow || !workspaceReady"
        :aria-busy="!workspaceReady"
    >
        <div
            v-if="pendingPayload && !busy"
            class="pos-recovery"
            role="alertdialog"
            aria-modal="true"
            aria-label="بررسی نتیجه ثبت فروش"
        >
            <div>
                <ReceiptText :size="35" />
                <h2>نتیجهٔ ثبت فروش در انتظار بررسی است</h2>
                <p>
                    برای جلوگیری از فروش تکراری، ابتدا نتیجهٔ همین درخواست را
                    بررسی کنید. اطلاعات فاکتور محفوظ است.
                </p>
                <p v-if="error" class="pos-error">{{ error }}</p>
                <button
                    type="button"
                    class="pos-primary w-full"
                    @click="retryCheckout"
                >
                    بررسی نتیجه و ادامه</button
                ><Link :href="dashboard()" class="pos-secondary mt-3"
                    >خروج از صندوق</Link
                >
            </div>
        </div>
        <header class="pos-header" :inert="Boolean(pendingPayload)">
            <div class="flex min-w-0 items-center gap-3">
                <div class="pos-brand"><Monitor :size="24" /></div>
                <div class="min-w-0">
                    <h1 class="truncate text-base font-bold">صندوق فروش</h1>
                    <p class="mt-0.5 truncate text-xs text-slate-400">
                        {{ store_name }}
                    </p>
                </div>
                <span v-if="session" class="pos-live hidden sm:inline-flex"
                    ><span />شیفت باز</span
                >
            </div>
            <div
                class="hidden items-center gap-5 text-sm text-slate-300 lg:flex"
            >
                <span>{{ dateLabel }}</span
                ><span class="flex items-center gap-2 tabular-nums"
                    ><Clock3 :size="16" />{{ timeLabel }}</span
                >
            </div>
            <div class="flex items-center gap-2">
                <button
                    v-if="can_review"
                    class="pos-header-button hidden sm:flex"
                    type="button"
                    @click="
                        checksOpen = true;
                        refreshActivity();
                    "
                >
                    <ReceiptText :size="17" /><span>رسیدگی چک‌ها</span
                    ><b v-if="pendingChecks.length" class="pos-count">{{
                        qty(pendingChecks.length)
                    }}</b></button
                ><button
                    class="pos-header-button"
                    type="button"
                    aria-label="تمام صفحه"
                    title="تمام صفحه"
                    @click="fullscreen"
                >
                    <Expand :size="18" /></button
                ><Link
                    :href="dashboard()"
                    class="pos-header-button"
                    aria-label="خروج از صندوق"
                    ><LogOut :size="18" /><span class="hidden sm:inline"
                        >خروج</span
                    ></Link
                >
            </div>
        </header>

        <div class="pos-workspace" :inert="Boolean(pendingPayload)">
            <section class="pos-catalog" aria-label="انتخاب کالا">
                <div class="pos-catalog-heading">
                    <div>
                        <span class="pos-eyebrow">انتخاب سریع</span>
                        <h2>کالاها</h2>
                    </div>
                    <span class="pos-warehouse"
                        ><Package :size="15" />{{ warehouseName }}</span
                    >
                </div>
                <form class="pos-search" @submit.prevent="scan">
                    <Barcode :size="22" class="text-slate-400" /><input
                        ref="searchInput"
                        v-model="search"
                        aria-label="جست‌وجوی کالا یا بارکد"
                        placeholder="بارکد بزنید یا نام کالا را بنویسید…"
                        autocomplete="off"
                        :disabled="!session || busy"
                    /><kbd>F2</kbd
                    ><button
                        v-if="search"
                        type="button"
                        aria-label="پاک‌کردن جست‌وجو"
                        @click="search = ''"
                    >
                        <X :size="16" /></button
                    ><button type="submit" aria-label="جست‌وجو">
                        <Search :size="19" />
                    </button>
                </form>
                <div class="pos-categories" aria-label="دسته‌بندی کالا">
                    <button
                        type="button"
                        :class="{ active: category === null }"
                        @click="category = null"
                    >
                        <LayoutGrid :size="15" />همه کالاها</button
                    ><button
                        v-for="item in categories"
                        :key="item.id"
                        type="button"
                        :class="{ active: category === item.id }"
                        @click="category = item.id"
                    >
                        {{ item.name }}
                    </button>
                </div>
                <div v-if="!session" class="pos-empty">
                    <div class="pos-empty-icon"><Monitor :size="36" /></div>
                    <h3>آمادهٔ یک روز فروش تازه؟</h3>
                    <p>شیفت را باز کنید و اولین فاکتور را بزنید.</p>
                    <button
                        type="button"
                        class="pos-primary mt-5"
                        @click="showShift"
                    >
                        شروع شیفت
                    </button>
                </div>
                <div v-else-if="!products.length" class="pos-empty">
                    <Package :size="38" class="text-slate-300" />
                    <p>
                        {{
                            productLoading
                                ? 'در حال دریافت کالاها…'
                                : 'کالایی برای این جست‌وجو پیدا نشد.'
                        }}
                    </p>
                </div>
                <div
                    v-else
                    class="pos-product-grid"
                    :aria-busy="productLoading"
                >
                    <button
                        v-for="(product, index) in products"
                        :key="product.id"
                        type="button"
                        class="pos-product"
                        :class="`tone-${index % 5}`"
                        :disabled="
                            parseNumber(product.stock_quantity) <= 0 || busy
                        "
                        @click="addProduct(product)"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="pos-product-code">{{
                                product.code
                            }}</span
                            ><Plus :size="17" />
                        </div>
                        <img
                            v-if="
                                product.image_url &&
                                !failedImages.has(product.image_url)
                            "
                            :src="product.image_url"
                            :alt="product.name"
                            class="pos-product-image"
                            loading="lazy"
                            decoding="async"
                            @error="failedImages.add(product.image_url)"
                        />
                        <strong>{{ product.name }}</strong
                        ><span
                            v-if="product.variant_color || product.variant_size"
                            class="text-xs opacity-65"
                            >{{
                                [product.variant_color, product.variant_size]
                                    .filter(Boolean)
                                    .join(' / ')
                            }}</span
                        >
                        <div class="pos-product-bottom">
                            <span class="pos-product-price"
                                >{{ money(product.sale_price)
                                }}<small>ریال</small></span
                            ><span class="pos-product-stock">{{
                                parseNumber(product.stock_quantity) > 0
                                    ? `${qty(product.stock_quantity)} ${product.unit}`
                                    : 'ناموجود'
                            }}</span>
                        </div>
                    </button>
                </div>
                <div class="pos-catalog-footer">
                    <span class="flex items-center gap-2"
                        ><Search :size="16" />برای کالاهای بیشتر جست‌وجو
                        کنید</span
                    ><span>{{
                        productLoading
                            ? 'به‌روزرسانی…'
                            : `${qty(products.length)} کالا`
                    }}</span>
                </div>
            </section>

            <section class="pos-invoice" aria-label="فاکتور فروش">
                <div class="pos-invoice-heading">
                    <div class="flex items-center gap-3">
                        <div class="pos-invoice-icon">
                            <ReceiptText :size="22" />
                        </div>
                        <div>
                            <h2>فاکتور {{ qty(activeCart.number) }}</h2>
                            <span
                                >{{ qty(lines.length) }} قلم ·
                                {{ qty(itemCount) }} واحد</span
                            >
                        </div>
                    </div>
                    <div class="flex gap-1">
                        <button
                            type="button"
                            class="pos-icon-button"
                            v-if="held.length"
                            title="فاکتورهای قبلی"
                            aria-label="فاکتورهای قبلی"
                            @click="heldOpen = true"
                        >
                            <ReceiptText :size="18" /><b
                                v-if="held.length"
                                class="pos-small-count"
                                >{{ qty(held.length) }}</b
                            ></button
                        ><button
                            type="button"
                            class="pos-icon-button text-rose-500"
                            :disabled="!lines.length || busy"
                            aria-label="پاک‌کردن فاکتور"
                            title="پاک‌کردن فاکتور"
                            @click="clearCart"
                        >
                            <Trash2 :size="18" />
                        </button>
                    </div>
                </div>
                <div class="pos-tabs-bar">
                    <div
                        class="pos-tabs"
                        role="tablist"
                        aria-label="فاکتورهای باز"
                        @keydown="tabKeydown"
                    >
                        <div
                            v-for="cart in workspace.tabs"
                            :key="cart.id"
                            class="pos-tab"
                            :class="{ active: cart.id === workspace.activeId }"
                        >
                            <button
                                :id="`pos-tab-${cart.id}`"
                                type="button"
                                role="tab"
                                :aria-selected="cart.id === workspace.activeId"
                                :tabindex="
                                    cart.id === workspace.activeId ? 0 : -1
                                "
                                :title="cart.customer?.name || 'مشتری نقدی'"
                                aria-controls="pos-invoice-panel"
                                :disabled="tabsLocked"
                                @click="switchTab(cart.id)"
                            >
                                <span
                                    >فاکتور {{ qty(cart.number)
                                    }}<b v-if="cart.lines.length">{{
                                        qty(cart.lines.length)
                                    }}</b></span
                                >
                                <small>{{
                                    cart.customer?.name || 'مشتری نقدی'
                                }}</small>
                            </button>
                            <button
                                type="button"
                                class="pos-tab-close"
                                :aria-label="`بستن فاکتور ${qty(cart.number)}`"
                                :disabled="
                                    tabsLocked || workspace.tabs.length <= 1
                                "
                                @click="closeTab(cart.id)"
                            >
                                <X :size="13" />
                            </button>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="pos-tab-add"
                        aria-label="فاکتور جدید"
                        :title="
                            workspace.tabs.length >= MAX_POS_CARTS
                                ? 'حداکثر ۵ فاکتور باز'
                                : 'فاکتور جدید'
                        "
                        :disabled="
                            !session ||
                            tabsLocked ||
                            workspace.tabs.length >= MAX_POS_CARTS
                        "
                        @click="addTab"
                    >
                        <Plus :size="19" /><small
                            >{{ qty(workspace.tabs.length) }}/۵</small
                        >
                    </button>
                </div>
                <div
                    id="pos-invoice-panel"
                    :key="activeCart.id"
                    class="pos-invoice-panel"
                    role="tabpanel"
                    :aria-labelledby="`pos-tab-${activeCart.id}`"
                >
                    <button
                        class="pos-customer"
                        type="button"
                        :disabled="busy"
                        @click="customerOpen = true"
                    >
                        <div class="pos-customer-icon">
                            <UserRound :size="21" />
                        </div>
                        <div class="flex-1 text-right">
                            <strong>{{
                                selectedCustomer?.name || 'مشتری نقدی'
                            }}</strong
                            ><span>{{
                                selectedCustomer?.phone ||
                                (selectedCustomer
                                    ? selectedCustomer.code
                                    : 'برای انتخاب مشتری کلیک کنید')
                            }}</span>
                        </div>
                        <kbd>F4</kbd><ChevronLeft :size="18" />
                    </button>
                    <div class="pos-cart-scroll">
                        <table v-if="lines.length" class="pos-cart-table">
                            <thead>
                                <tr>
                                    <th class="text-right">کالا</th>
                                    <th>تعداد</th>
                                    <th>قیمت واحد</th>
                                    <th>مبلغ</th>
                                    <th><span class="sr-only">حذف</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(line, index) in lines"
                                    :key="line.id"
                                >
                                    <td>
                                        <div class="flex items-start gap-2">
                                            <span class="pos-row-number">{{
                                                qty(index + 1)
                                            }}</span>
                                            <div>
                                                <strong>{{ line.name }}</strong
                                                ><small
                                                    >{{ line.code }} ·
                                                    {{ line.unit }}</small
                                                ><label
                                                    class="pos-line-discount"
                                                    >تخفیف<input
                                                        v-model="
                                                            line.discount_amount
                                                        "
                                                        :disabled="busy"
                                                        inputmode="decimal"
                                                        :aria-label="`تخفیف ${line.name}`"
                                                        dir="ltr"
                                                /></label>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="pos-quantity">
                                            <button
                                                type="button"
                                                :disabled="busy"
                                                :aria-label="`افزایش ${line.name}`"
                                                @click="changeQuantity(line, 1)"
                                            >
                                                <Plus :size="14" /></button
                                            ><input
                                                v-model="line.quantity"
                                                :disabled="busy"
                                                inputmode="decimal"
                                                :aria-label="`تعداد ${line.name}`"
                                                dir="ltr"
                                            /><button
                                                type="button"
                                                :disabled="busy"
                                                :aria-label="`کاهش ${line.name}`"
                                                @click="
                                                    changeQuantity(line, -1)
                                                "
                                            >
                                                <Minus :size="14" />
                                            </button>
                                        </div>
                                        <span
                                            v-if="
                                                parseNumber(line.quantity) >
                                                parseNumber(line.stock_quantity)
                                            "
                                            class="text-xs text-rose-600"
                                            >کسری موجودی</span
                                        >
                                    </td>
                                    <td>
                                        <input
                                            v-model="line.unit_price"
                                            class="pos-price-input"
                                            :disabled="busy"
                                            inputmode="decimal"
                                            :aria-label="`قیمت ${line.name}`"
                                            dir="ltr"
                                        />
                                    </td>
                                    <td class="font-bold tabular-nums">
                                        {{
                                            money(
                                                amountString(
                                                    lineAmounts(line).total,
                                                ),
                                            )
                                        }}
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            class="pos-remove"
                                            :disabled="busy"
                                            :aria-label="`حذف ${line.name}`"
                                            @click="lines.splice(index, 1)"
                                        >
                                            <X :size="15" />
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div v-else class="pos-empty pos-cart-empty">
                            <div class="pos-empty-icon">
                                <ShoppingBasket :size="42" stroke-width="1.4" />
                            </div>
                            <h3>سبد فروش آماده است</h3>
                            <p>کالا را انتخاب کنید یا بارکد بزنید.</p>
                            <div class="pos-empty-rule" />
                        </div>
                    </div>
                    <div class="pos-invoice-bottom">
                        <input
                            v-model="notes"
                            class="pos-note"
                            :disabled="busy"
                            placeholder="یادداشت فاکتور…"
                            aria-label="یادداشت فاکتور"
                            maxlength="2000"
                        />
                        <div class="pos-totals">
                            <span
                                >جمع کالاها<strong>{{
                                    money(subtotal)
                                }}</strong></span
                            ><span
                                >تخفیف<strong class="text-rose-600">{{
                                    money(discount)
                                }}</strong></span
                            ><span
                                >مالیات و عوارض<strong>{{
                                    money(tax)
                                }}</strong></span
                            >
                        </div>
                        <div class="pos-grand-total">
                            <span>مبلغ قابل پرداخت</span>
                            <div>
                                <strong>{{ money(total) }}</strong
                                ><small>ریال</small>
                            </div>
                        </div>
                        <p
                            v-if="error && !paymentOpen && !shiftOpen"
                            class="pos-error mb-3"
                            role="alert"
                        >
                            {{ error }}
                        </p>
                        <p v-if="notice" class="pos-notice">{{ notice }}</p>
                        <div class="pos-checkout-actions">
                            <button
                                type="button"
                                class="pos-primary"
                                :disabled="
                                    !session ||
                                    !lines.length ||
                                    invalidLines ||
                                    busy
                                "
                                @click="openPayment"
                            >
                                <CreditCard :size="22" /><span
                                    >پرداخت و ثبت فروش</span
                                ><kbd>F8</kbd></button
                            ><button
                                type="button"
                                class="pos-secondary"
                                :disabled="
                                    !session ||
                                    tabsLocked ||
                                    workspace.tabs.length >= MAX_POS_CARTS
                                "
                                @click="addTab"
                            >
                                <Plus :size="19" /><span>فاکتور جدید</span>
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <footer class="pos-statusbar" :inert="Boolean(pendingPayload)">
            <div class="flex min-w-0 items-center gap-3">
                <span class="pos-avatar">{{ user.name.slice(0, 1) }}</span
                ><span class="truncate">{{ user.name }}</span
                ><span class="pos-divider" /><button
                    type="button"
                    @click="showShift"
                >
                    {{ session ? `شیفت ${qty(session.id)}` : 'شروع شیفت'
                    }}<ArrowUpLeft :size="13" />
                </button>
            </div>
            <div class="hidden items-center gap-5 sm:flex">
                <button
                    type="button"
                    @click="
                        activityOpen = true;
                        refreshActivity();
                    "
                >
                    <ReceiptText :size="15" />{{
                        qty(session?.sales_count || 0)
                    }}
                    فروش این شیفت</button
                ><span>سال مالی {{ fiscal_year || 'تعریف نشده' }}</span>
            </div>
            <button
                v-if="can_review"
                class="sm:hidden"
                type="button"
                @click="
                    checksOpen = true;
                    refreshActivity();
                "
            >
                رسیدگی چک‌ها</button
            ><button type="button" class="pos-shift-button" @click="showShift">
                <Wallet :size="16" />{{
                    session ? 'بستن شیفت' : 'بازکردن صندوق'
                }}
            </button>
        </footer>

        <PosCheckoutDialog
            :key="activeCart.id + activeCart.token"
            v-model:draft="paymentDraft"
            v-model:open="paymentOpen"
            :blocked="viewportTooNarrow"
            :total="total"
            :customer="selectedCustomer"
            :card-available="Boolean(session?.card_account_id)"
            :today="today"
            :busy="busy"
            :error="error"
            @submit="checkout"
        />
        <PosCheckReview
            v-if="can_review"
            v-model:open="checksOpen"
            :blocked="viewportTooNarrow"
            :checks="pendingChecks"
            :today="today"
            @resolved="refreshActivity"
        />

        <Dialog v-model:open="customerDialog"
            ><DialogContent class="pos-dialog sm:max-w-lg" dir="rtl"
                ><DialogHeader class="text-right"
                    ><DialogTitle>انتخاب مشتری</DialogTitle
                    ><DialogDescription
                        >برای فروش نسیه یا چکی، مشتری مشخص انتخاب
                        کنید.</DialogDescription
                    ></DialogHeader
                >
                <div class="pos-search">
                    <Search :size="19" /><input
                        v-model="customerSearch"
                        autofocus
                        placeholder="نام، موبایل یا کد مشتری…"
                        aria-label="جست‌وجوی مشتری"
                    />
                </div>
                <button
                    type="button"
                    class="pos-review-row"
                    @click="
                        selectedCustomer = null;
                        customerOpen = false;
                    "
                >
                    <span>مشتری نقدی</span
                    ><span class="text-xs text-slate-500"
                        >پرداخت کامل نقد یا کارت</span
                    >
                </button>
                <div class="max-h-80 space-y-2 overflow-auto">
                    <button
                        v-for="customer in customerResults"
                        :key="customer.id"
                        type="button"
                        class="pos-review-row"
                        @click="
                            selectedCustomer = customer;
                            customerOpen = false;
                        "
                    >
                        <div class="text-right">
                            <strong>{{ customer.name }}</strong>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ customer.phone || customer.code }}
                            </p>
                        </div>
                        <Check
                            v-if="selectedCustomer?.id === customer.id"
                            :size="19"
                            class="text-emerald-700"
                        /><ChevronLeft v-else :size="17" />
                    </button>
                    <p
                        v-if="!customerResults.length"
                        class="py-6 text-center text-sm text-slate-500"
                    >
                        {{
                            customerLoading
                                ? 'در حال جست‌وجو…'
                                : 'مشتری پیدا نشد.'
                        }}
                    </p>
                </div></DialogContent
            ></Dialog
        >

        <Dialog
            :open="shiftDialog"
            @update:open="!busy && (shiftDialog = $event)"
            ><DialogContent class="pos-dialog sm:max-w-lg" dir="rtl"
                ><DialogHeader class="text-right"
                    ><DialogTitle>{{
                        session ? 'تحویل و بستن شیفت' : 'شروع شیفت صندوق'
                    }}</DialogTitle
                    ><DialogDescription>{{
                        session
                            ? 'وجه نقد موجود را بشمارید؛ چک‌های منتظر حسابدار محفوظ می‌مانند.'
                            : 'انبار و حساب‌های دریافت این صندوق را مشخص کنید.'
                    }}</DialogDescription></DialogHeader
                >
                <form class="space-y-4" @submit.prevent="submitShift">
                    <template v-if="!session"
                        ><label class="pos-field"
                            >انبار فروش<select
                                v-model="warehouseId"
                                class="pos-input"
                                required
                            >
                                <option value="" disabled>انتخاب انبار</option>
                                <option
                                    v-for="warehouse in warehouses"
                                    :key="warehouse.id"
                                    :value="warehouse.id"
                                >
                                    {{ warehouse.name }}
                                </option>
                            </select></label
                        ><label class="pos-field"
                            >صندوق نقدی<select
                                v-model="cashId"
                                class="pos-input"
                                required
                            >
                                <option value="" disabled>
                                    انتخاب صندوق نقدی
                                </option>
                                <option
                                    v-for="account in treasury_accounts.filter(
                                        (a) => a.type === 'cash',
                                    )"
                                    :key="account.id"
                                    :value="account.id"
                                >
                                    {{ account.name }}
                                </option>
                            </select></label
                        ><label class="pos-field"
                            >کارت‌خوان<select
                                v-model="cardId"
                                class="pos-input"
                            >
                                <option value="">بدون کارت‌خوان</option>
                                <option
                                    v-for="account in treasury_accounts.filter(
                                        (a) => a.type === 'card',
                                    )"
                                    :key="account.id"
                                    :value="account.id"
                                >
                                    {{ account.name }}
                                </option>
                            </select></label
                        ><label class="pos-field"
                            >وجه نقد ابتدای شیفت (ریال)<input
                                v-model="openingCash"
                                class="pos-input"
                                inputmode="decimal"
                                required
                                dir="ltr"
                        /></label>
                        <p class="text-xs leading-6 text-slate-500">
                            وجه ابتدای شیفت برای تطبیق ثبت می‌شود؛ جابه‌جایی پول
                            و سند افتتاحیه از خزانه انجام می‌شود.
                        </p></template
                    ><template v-else
                        ><div class="pos-payment-total">
                            <span>وجه نقد مورد انتظار</span
                            ><strong
                                >{{ money(session.expected_cash) }} ریال</strong
                            >
                        </div>
                        <label class="pos-field"
                            >وجه نقد شمارش‌شده (ریال)<input
                                v-model="countedCash"
                                class="pos-input"
                                inputmode="decimal"
                                required
                                dir="ltr"
                        /></label>
                        <p
                            :class="
                                difference === 0
                                    ? 'text-emerald-700'
                                    : 'text-amber-700'
                            "
                            class="text-sm"
                        >
                            {{
                                difference === 0
                                    ? 'وجه صندوق مطابق انتظار است.'
                                    : `مغایرت: ${money(difference)} ریال`
                            }}
                        </p>
                        <label class="pos-field"
                            >توضیح تحویل / مغایرت<textarea
                                v-model="closingNotes"
                                class="pos-input"
                                rows="3"
                                maxlength="2000"
                                :required="difference !== 0"
                            />
                        </label>
                        <p v-if="lines.length" class="pos-error">
                            ابتدا فاکتور باز را ثبت کنید یا در انتظار قرار دهید.
                        </p>
                        <p class="text-xs leading-6 text-slate-500">
                            مغایرت برای بررسی ثبت می‌شود؛ سند تعدیل خودکار صادر
                            نمی‌شود.
                        </p></template
                    >
                    <p v-if="error" class="pos-error" role="alert">
                        {{ error }}
                    </p>
                    <button
                        type="submit"
                        class="pos-primary w-full"
                        :disabled="busy || Boolean(session && lines.length)"
                    >
                        <Monitor :size="19" />{{
                            busy
                                ? 'در حال ثبت…'
                                : session
                                  ? 'تأیید و بستن شیفت'
                                  : 'شروع فروش'
                        }}
                    </button>
                </form></DialogContent
            ></Dialog
        >

        <Dialog v-model:open="heldDialog"
            ><DialogContent class="pos-dialog sm:max-w-lg" dir="rtl"
                ><DialogHeader class="text-right"
                    ><DialogTitle>فاکتورهای قبلی</DialogTitle
                    ><DialogDescription
                        >این سبدها فقط در همین مرورگر ذخیره‌اند و اثر مالی یا
                        انباری ندارند.</DialogDescription
                    ></DialogHeader
                >
                <div class="max-h-96 space-y-2 overflow-auto">
                    <p
                        v-if="workspace.tabs.length >= MAX_POS_CARTS"
                        class="pos-notice"
                    >
                        برای بازکردن فاکتور قبلی، ابتدا یکی از ۵ تب را ببندید.
                    </p>
                    <div
                        v-for="cart in held"
                        :key="cart.id"
                        class="flex items-center gap-2"
                    >
                        <button
                            type="button"
                            class="pos-review-row flex-1"
                            :disabled="
                                tabsLocked ||
                                workspace.tabs.length >= MAX_POS_CARTS
                            "
                            @click="restoreHeld(cart.id)"
                        >
                            <div class="text-right">
                                <strong>{{
                                    cart.customer?.name || 'مشتری نقدی'
                                }}</strong>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ qty(cart.lines.length) }} قلم کالا
                                </p>
                            </div>
                            <ChevronLeft :size="18" /></button
                        ><button
                            type="button"
                            class="pos-icon-button text-rose-500"
                            aria-label="حذف فاکتور در انتظار"
                            @click="removeHeld(cart.id)"
                        >
                            <Trash2 :size="17" />
                        </button>
                    </div>
                    <p
                        v-if="!held.length"
                        class="py-8 text-center text-slate-500"
                    >
                        فاکتور در انتظار ندارید.
                    </p>
                </div></DialogContent
            ></Dialog
        >

        <Dialog v-model:open="activityDialog"
            ><DialogContent
                class="pos-dialog max-h-[90dvh] overflow-y-auto sm:max-w-xl"
                dir="rtl"
                ><DialogHeader class="text-right"
                    ><DialogTitle>فروش‌های این شیفت</DialogTitle
                    ><DialogDescription
                        >آخرین ۲۰ فاکتور ثبت‌شده در شیفت جاری</DialogDescription
                    ></DialogHeader
                ><Link
                    v-for="sale in recentSales"
                    :key="sale.id"
                    :href="saleUrl(sale.invoice_id)"
                    class="pos-review-row"
                    ><div class="text-right">
                        <strong>{{ sale.invoice_number }}</strong>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ sale.customer_name }}
                        </p>
                    </div>
                    <div class="text-left">
                        <strong>{{ money(sale.total) }} ریال</strong>
                        <p
                            class="mt-1 text-xs"
                            :class="
                                Number(sale.outstanding) > 0
                                    ? 'text-amber-700'
                                    : 'text-emerald-700'
                            "
                        >
                            {{
                                Number(sale.outstanding) > 0
                                    ? `مانده ${money(sale.outstanding)}`
                                    : 'تسویه‌شده'
                            }}
                        </p>
                    </div>
                    <ArrowLeft :size="17"
                /></Link>
                <p
                    v-if="!recentSales.length"
                    class="py-8 text-center text-slate-500"
                >
                    هنوز فروشی ثبت نشده است.
                </p></DialogContent
            ></Dialog
        >

        <Dialog v-model:open="successDialog"
            ><DialogContent class="pos-dialog sm:max-w-md" dir="rtl"
                ><DialogHeader class="text-center"
                    ><div class="pos-success-check"><Check :size="34" /></div>
                    <DialogTitle class="text-xl"
                        >فروش با موفقیت ثبت شد</DialogTitle
                    ><DialogDescription
                        >فاکتور {{ lastSale?.invoice_number }} ·
                        {{ lastSale?.customer_name }}</DialogDescription
                    ></DialogHeader
                >
                <div v-if="lastSale" class="space-y-4">
                    <div class="pos-payment-total justify-center">
                        <strong class="text-3xl"
                            >{{ money(lastSale.total) }}
                            <small class="text-sm font-normal"
                                >ریال</small
                            ></strong
                        >
                    </div>
                    <div class="space-y-2 text-sm">
                        <p class="flex justify-between">
                            <span>ماندهٔ فاکتور</span
                            ><strong
                                >{{ money(lastSale.outstanding) }} ریال</strong
                            >
                        </p>
                        <p
                            v-if="Number(lastSale.pending_check) > 0"
                            class="flex justify-between text-amber-700"
                        >
                            <span>از این مانده، چک منتظر حسابدار</span
                            ><strong
                                >{{
                                    money(lastSale.pending_check)
                                }}
                                ریال</strong
                            >
                        </p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <button
                            type="button"
                            class="pos-primary"
                            @click="
                                successOpen = false;
                                nextTick(() => searchInput?.focus());
                            "
                        >
                            <Plus :size="19" />فروش جدید</button
                        ><Link
                            :href="saleUrl(lastSale.invoice_id)"
                            target="_blank"
                            class="pos-secondary"
                            ><Printer :size="18" />نمایش و چاپ</Link
                        >
                    </div>
                </div></DialogContent
            ></Dialog
        >
    </div>
    <PosViewportNotice :open="viewportTooNarrow" @fullscreen="fullscreen" />
</template>
