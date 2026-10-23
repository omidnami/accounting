<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Banknote,
    Building2,
    CalendarDays,
    Check,
    CircleAlert,
    CreditCard,
    HandCoins,
    Landmark,
    ReceiptText,
    Search,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { normalizeNumber, parseNumber } from '@/lib/number';
import { create as createCheck } from '@/routes/checks';
import { suppliers as supplierLookup } from '@/routes/purchases/lookups';
import { invoices as invoiceLookup } from '@/routes/payments/lookups';
import {
    create as createPayment,
    index as paymentsIndex,
    store as storePayment,
} from '@/routes/payments';

type Supplier = {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    payment_terms: string | null;
};
type Invoice = {
    id: number;
    invoice_number: string;
    invoice_date: string;
    status: 'confirmed' | 'partially_paid';
    total: string;
    outstanding: string;
};
type Allocation = Invoice & { amount: string };
type Treasury = {
    id: number;
    name: string;
    type: 'cash' | 'bank' | 'card';
    is_default: boolean;
};
type PendingCheck = {
    id: number;
    document_number: string | null;
    check_number: string;
    bank_name: string;
    due_date: string;
    amount: string;
};
type Method = 'cash' | 'bank' | 'card' | 'check';

const props = defineProps<{
    defaults: { payment_date: string; submission_token: string };
    fiscal_year: { title: string; starts_on: string; ends_on: string };
    initial_supplier: Supplier | null;
    initial_invoices: Invoice[];
    initial_checks: PendingCheck[];
    initial_invoice_id: number | null;
    initial_invoice_ids: number[];
    initial_check_id: number | null;
    treasury_accounts: Treasury[];
}>();

const initialInvoiceIds = props.initial_invoice_ids.length
    ? props.initial_invoice_ids
    : props.initial_invoice_id === null
      ? []
      : [props.initial_invoice_id];
const initialAllocations =
    initialInvoiceIds.length === 0
        ? []
        : props.initial_invoices
              .filter((invoice) => initialInvoiceIds.includes(invoice.id))
              .map((invoice) => ({
                  ...invoice,
                  amount: invoice.outstanding,
              }));
const form = useForm({
    submission_token: props.defaults.submission_token,
    supplier_id: props.initial_supplier?.id.toString() ?? '',
    payment_date: props.defaults.payment_date,
    payment_method: (props.initial_check_id === null
        ? 'cash'
        : 'check') as Method,
    check_id: props.initial_check_id?.toString() ?? '',
    treasury_account_id: '',
    reference: '',
    description: '',
    allocations: initialAllocations as Allocation[],
});
const selectedSupplier = ref<Supplier | null>(props.initial_supplier);
const supplierSearch = ref('');
const supplierResults = ref<Supplier[]>([]);
const supplierLoading = ref(false);
const openInvoices = ref<Invoice[]>(props.initial_invoices);
const pendingChecks = ref<PendingCheck[]>(props.initial_checks);
const invoiceLoading = ref(false);
const lookupError = ref('');
const clientError = ref('');
let timer: ReturnType<typeof setTimeout> | undefined;
let requestId = 0;

const money = new Intl.NumberFormat('fa-IR');
const date = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
});
const total = computed(() =>
    form.allocations.reduce(
        (sum, allocation) => sum + parseNumber(allocation.amount),
        0,
    ),
);
const eligibleAccounts = computed(() =>
    props.treasury_accounts.filter((account) => {
        const type = {
            cash: 'cash',
            bank: 'bank',
            card: 'card',
            check: null,
        }[form.payment_method];

        return account.type === type;
    }),
);
const selectedCheck = computed(() =>
    pendingChecks.value.find((check) => check.id.toString() === form.check_id),
);
const fieldError = (key: string) =>
    (form.errors as Record<string, string>)[key];
const allocationError = (index: number) =>
    (form.errors as Record<string, string>)[`allocations.${index}.amount`];

const getJson = async <T>(url: string): Promise<T> => {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) throw new Error('lookup');

    return (await response.json()) as T;
};
const loadSuppliers = async () => {
    const id = ++requestId;
    supplierLoading.value = true;

    try {
        const response = await getJson<{ data: Supplier[] }>(
            supplierLookup.url({
                query: { search: supplierSearch.value.trim() || undefined },
            }),
        );

        if (id === requestId) supplierResults.value = response.data;
    } catch {
        if (id === requestId) {
            supplierResults.value = [];
            lookupError.value = 'دریافت فهرست تأمین‌کنندگان انجام نشد.';
        }
    } finally {
        if (id === requestId) supplierLoading.value = false;
    }
};
const loadInvoices = async () => {
    if (!form.supplier_id) return;

    invoiceLoading.value = true;
    lookupError.value = '';

    try {
        const response = await getJson<{
            supplier: Supplier;
            invoices: Invoice[];
            checks: PendingCheck[];
        }>(
            invoiceLookup.url({
                query: { supplier_id: Number(form.supplier_id) },
            }),
        );
        selectedSupplier.value = response.supplier;
        openInvoices.value = response.invoices;
        pendingChecks.value = response.checks;
    } catch {
        lookupError.value = 'دریافت فاکتورهای پرداختنی انجام نشد.';
    } finally {
        invoiceLoading.value = false;
    }
};
watch(supplierSearch, () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(loadSuppliers, 250);
});
watch(
    () => form.payment_method,
    () => {
        form.check_id = '';

        if (form.payment_method === 'check') {
            form.treasury_account_id = '';
            return;
        }

        form.treasury_account_id =
            eligibleAccounts.value
                .find((account) => account.is_default)
                ?.id.toString() ??
            eligibleAccounts.value[0]?.id.toString() ??
            '';
    },
);
onMounted(() => {
    if (!selectedSupplier.value) void loadSuppliers();

    if (form.payment_method === 'check') {
        return;
    }

    form.treasury_account_id =
        eligibleAccounts.value
            .find((account) => account.is_default)
            ?.id.toString() ??
        eligibleAccounts.value[0]?.id.toString() ??
        '';
});
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
});

const selectSupplier = async (supplier: Supplier) => {
    selectedSupplier.value = supplier;
    form.supplier_id = supplier.id.toString();
    form.allocations = [];
    pendingChecks.value = [];
    supplierSearch.value = '';
    supplierResults.value = [];
    await loadInvoices();
};
const clearSupplier = () => {
    selectedSupplier.value = null;
    form.supplier_id = '';
    form.allocations = [];
    openInvoices.value = [];
    pendingChecks.value = [];
    void loadSuppliers();
};
const toggleInvoice = (invoice: Invoice) => {
    const index = form.allocations.findIndex(
        (allocation) => allocation.id === invoice.id,
    );

    if (index >= 0) {
        form.allocations.splice(index, 1);
    } else {
        form.allocations.push({ ...invoice, amount: invoice.outstanding });
    }
};
const isSelected = (id: number) =>
    form.allocations.some((allocation) => allocation.id === id);
const submit = () => {
    clientError.value = '';

    if (!form.supplier_id) {
        clientError.value = 'ابتدا تأمین‌کننده را انتخاب کنید.';
        return;
    }
    if (!form.allocations.length) {
        clientError.value = 'حداقل یک فاکتور را انتخاب کنید.';
        return;
    }

    const invalid = form.allocations.find((allocation) => {
        const amount = parseNumber(allocation.amount);

        return amount <= 0 || amount > parseNumber(allocation.outstanding);
    });

    if (invalid) {
        clientError.value = `مبلغ فاکتور ${invalid.invoice_number} از مانده بیشتر است.`;
        return;
    }

    if (form.payment_method === 'check') {
        if (!selectedCheck.value) {
            clientError.value = 'چک پرداختی مورد استفاده را انتخاب کنید.';
            return;
        }

        if (
            Math.abs(parseNumber(selectedCheck.value.amount) - total.value) >
            0.009
        ) {
            clientError.value =
                'مبلغ چک باید دقیقاً برابر جمع تخصیص فاکتورها باشد.';
            return;
        }
    }

    form.transform((data) => ({
        ...data,
        check_id:
            data.payment_method === 'check' ? Number(data.check_id) : null,
        treasury_account_id:
            data.payment_method === 'check'
                ? null
                : data.treasury_account_id || null,
        allocations: data.allocations.map((allocation) => ({
            invoice_id: allocation.id,
            amount: normalizeNumber(allocation.amount),
        })),
    })).post(storePayment().url, { preserveScroll: true });
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'پرداخت‌ها', href: paymentsIndex() },
            { title: 'پرداخت جدید', href: createPayment() },
        ],
    },
});
</script>

<template>
    <Head title="پرداخت جدید" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div class="flex justify-between gap-4">
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <HandCoins class="size-5" />
                        <span class="text-xs font-bold"
                            >پرداخت به تأمین‌کننده</span
                        >
                    </div>
                    <h1 class="text-2xl font-black sm:text-3xl">
                        ثبت پرداخت و تسویه خرید
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        سال مالی {{ fiscal_year.title }} · پرداخت پس از ثبت،
                        بدهی و سند خزانه را به‌روزرسانی می‌کند.
                    </p>
                </div>
                <Button as-child variant="outline" class="h-10 rounded-xl">
                    <Link :href="paymentsIndex()">
                        <ArrowRight class="size-4" /> بازگشت
                    </Link>
                </Button>
            </div>
        </section>

        <div v-if="lookupError" class="rounded-xl bg-amber-500/10 p-3 text-xs">
            {{ lookupError }}
        </div>

        <form
            class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_340px]"
            @submit.prevent="submit"
        >
            <div class="space-y-6">
                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="mb-4 text-sm font-bold">تأمین‌کننده</h2>
                    <div
                        v-if="selectedSupplier"
                        class="flex justify-between rounded-2xl border border-primary/20 bg-primary/[.045] p-4"
                    >
                        <div>
                            <p class="text-sm font-bold">
                                {{ selectedSupplier.name }}
                            </p>
                            <p class="mt-1 text-[10px] text-muted-foreground">
                                {{ selectedSupplier.code }} ·
                                {{
                                    selectedSupplier.payment_terms ??
                                    'بدون شرایط'
                                }}
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="clearSupplier"
                            >تغییر</Button
                        >
                    </div>
                    <div v-else>
                        <div class="relative">
                            <Search
                                class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                            />
                            <Input
                                v-model="supplierSearch"
                                placeholder="جست‌وجوی تأمین‌کننده..."
                                class="h-11 rounded-xl pr-9"
                            />
                        </div>
                        <div
                            class="mt-2 max-h-60 overflow-y-auto rounded-xl border bg-background"
                        >
                            <p
                                v-if="supplierLoading"
                                class="p-3 text-xs text-muted-foreground"
                            >
                                در حال جست‌وجو...
                            </p>
                            <button
                                v-for="supplier in supplierResults"
                                :key="supplier.id"
                                type="button"
                                class="flex w-full justify-between border-b px-4 py-3 text-right last:border-0"
                                @click="selectSupplier(supplier)"
                            >
                                <span>
                                    <span class="block text-xs font-semibold">{{
                                        supplier.name
                                    }}</span>
                                    <span
                                        class="mt-1 block text-[10px] text-muted-foreground"
                                        >{{ supplier.code }} ·
                                        {{
                                            supplier.phone ?? 'بدون تلفن'
                                        }}</span
                                    >
                                </span>
                                <Check class="size-4 text-primary" />
                            </button>
                        </div>
                        <InputError
                            class="mt-2"
                            :message="form.errors.supplier_id"
                        />
                    </div>
                </section>

                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="text-sm font-bold">فاکتورهای پرداختنی</h2>
                    <div
                        v-if="invoiceLoading"
                        class="py-10 text-center text-xs text-muted-foreground"
                    >
                        در حال دریافت مانده‌ها...
                    </div>
                    <div v-else-if="openInvoices.length" class="mt-4 space-y-3">
                        <article
                            v-for="invoice in openInvoices"
                            :key="invoice.id"
                            class="rounded-2xl border p-4"
                            :class="
                                isSelected(invoice.id)
                                    ? 'border-primary/30 bg-primary/[.035]'
                                    : ''
                            "
                        >
                            <button
                                type="button"
                                class="flex w-full justify-between text-right"
                                @click="toggleInvoice(invoice)"
                            >
                                <span>
                                    <span
                                        class="block font-mono text-xs font-bold"
                                        >{{ invoice.invoice_number }}</span
                                    >
                                    <span
                                        class="mt-1 block text-[10px] text-muted-foreground"
                                        >{{
                                            date.format(
                                                new Date(
                                                    `${invoice.invoice_date}T00:00:00`,
                                                ),
                                            )
                                        }}
                                        · مانده
                                        {{
                                            money.format(
                                                Number(invoice.outstanding),
                                            )
                                        }}</span
                                    >
                                </span>
                                <Check
                                    v-if="isSelected(invoice.id)"
                                    class="size-4 text-primary"
                                />
                            </button>
                            <div
                                v-if="isSelected(invoice.id)"
                                class="mt-3 grid gap-2"
                            >
                                <Label class="text-[10px]">مبلغ پرداخت</Label>
                                <Input
                                    v-model="
                                        form.allocations.find(
                                            (allocation) =>
                                                allocation.id === invoice.id,
                                        )!.amount
                                    "
                                    inputmode="decimal"
                                    dir="ltr"
                                    class="h-10 text-left"
                                />
                                <InputError
                                    :message="
                                        allocationError(
                                            form.allocations.findIndex(
                                                (allocation) =>
                                                    allocation.id ===
                                                    invoice.id,
                                            ),
                                        )
                                    "
                                />
                            </div>
                        </article>
                    </div>
                    <div
                        v-else-if="selectedSupplier"
                        class="mt-4 rounded-2xl border border-dashed py-10 text-center text-xs text-muted-foreground"
                    >
                        فاکتور پرداختنی قابل تسویه‌ای وجود ندارد.
                    </div>
                    <div
                        v-else
                        class="mt-4 rounded-2xl border border-dashed py-10 text-center text-xs text-muted-foreground"
                    >
                        ابتدا تأمین‌کننده را انتخاب کنید.
                    </div>
                    <InputError
                        class="mt-2"
                        :message="form.errors.allocations"
                    />
                </section>

                <section class="rounded-2xl border bg-card p-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="payment_date">تاریخ پرداخت *</Label>
                            <div class="relative">
                                <CalendarDays
                                    class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                                />
                                <Input
                                    id="payment_date"
                                    v-model="form.payment_date"
                                    type="date"
                                    :min="fiscal_year.starts_on"
                                    :max="fiscal_year.ends_on"
                                    class="h-11 pr-9"
                                />
                            </div>
                            <InputError :message="form.errors.payment_date" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="reference">کد مرجع</Label>
                            <Input
                                id="reference"
                                v-model="form.reference"
                                class="h-11"
                            />
                            <InputError :message="form.errors.reference" />
                        </div>
                    </div>

                    <div class="mt-5">
                        <Label>روش پرداخت *</Label>
                        <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <button
                                v-for="method in [
                                    {
                                        value: 'cash',
                                        label: 'نقدی',
                                        icon: Banknote,
                                    },
                                    {
                                        value: 'bank',
                                        label: 'بانکی',
                                        icon: Landmark,
                                    },
                                    {
                                        value: 'card',
                                        label: 'کارتی',
                                        icon: CreditCard,
                                    },
                                    {
                                        value: 'check',
                                        label: 'چکی',
                                        icon: ReceiptText,
                                    },
                                ] as const"
                                :key="method.value"
                                type="button"
                                class="rounded-2xl border p-3 text-right"
                                :class="
                                    form.payment_method === method.value
                                        ? 'border-primary/40 bg-primary/[.055]'
                                        : ''
                                "
                                @click="form.payment_method = method.value"
                            >
                                <component
                                    :is="method.icon"
                                    class="mb-2 size-5 text-primary"
                                />
                                <span class="text-xs font-semibold">{{
                                    method.label
                                }}</span>
                            </button>
                        </div>
                    </div>

                    <div
                        v-if="form.payment_method === 'check'"
                        class="mt-5 grid gap-2"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <Label for="check_id">چک پرداختی *</Label>
                            <Button
                                v-if="selectedSupplier"
                                as-child
                                variant="ghost"
                                size="sm"
                                class="h-8"
                            >
                                <Link
                                    :href="
                                        createCheck.url({
                                            query: {
                                                type: 'payable',
                                                supplier_id:
                                                    selectedSupplier.id,
                                                return_to: 'payment',
                                                invoice_ids: form.allocations
                                                    .map(
                                                        (allocation) =>
                                                            allocation.id,
                                                    )
                                                    .join(','),
                                            },
                                        })
                                    "
                                >
                                    ثبت چک جدید
                                </Link>
                            </Button>
                        </div>
                        <select
                            id="check_id"
                            v-model="form.check_id"
                            class="h-11 rounded-xl border bg-background px-3 text-sm"
                        >
                            <option value="">انتخاب چک در انتظار</option>
                            <option
                                v-for="check in pendingChecks"
                                :key="check.id"
                                :value="check.id.toString()"
                            >
                                {{
                                    check.document_number ?? check.check_number
                                }}
                                ·
                                {{ check.check_number }} ·
                                {{ check.bank_name }} ·
                                {{ money.format(Number(check.amount)) }} ریال
                            </option>
                        </select>
                        <p
                            v-if="selectedSupplier && !pendingChecks.length"
                            class="text-[10px] leading-5 text-muted-foreground"
                        >
                            برای این تأمین‌کننده چک در انتظار وجود ندارد.
                        </p>
                        <InputError :message="form.errors.check_id" />
                    </div>

                    <div
                        v-if="form.payment_method !== 'check'"
                        class="mt-5 grid gap-2"
                    >
                        <Label for="treasury">حساب خزانه</Label>
                        <select
                            id="treasury"
                            v-model="form.treasury_account_id"
                            class="h-11 rounded-xl border bg-background px-3 text-sm"
                        >
                            <option value="">حساب پیش‌فرض</option>
                            <option
                                v-for="account in eligibleAccounts"
                                :key="account.id"
                                :value="account.id.toString()"
                            >
                                {{ account.name }}
                            </option>
                        </select>
                        <InputError
                            :message="form.errors.treasury_account_id"
                        />
                    </div>

                    <div class="mt-5 grid gap-2">
                        <Label for="description">توضیحات</Label>
                        <textarea
                            id="description"
                            v-model="form.description"
                            rows="3"
                            class="rounded-xl border px-4 py-3 text-sm"
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                </section>
            </div>

            <aside class="space-y-4 xl:sticky xl:top-6">
                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="text-sm font-bold">خلاصه پرداخت</h2>
                    <div class="mt-5 flex justify-between">
                        <span class="text-xs text-muted-foreground"
                            >مبلغ کل</span
                        >
                        <span class="text-xl font-black"
                            >{{ money.format(total) }}
                            <small class="text-[9px]">ریال</small></span
                        >
                    </div>
                    <p class="mt-2 text-[10px] text-muted-foreground">
                        {{ form.allocations.length }} فاکتور انتخاب شده است.
                    </p>
                    <div
                        v-if="clientError || fieldError('payment')"
                        class="mt-4 flex gap-2 rounded-xl bg-red-500/10 p-3 text-[10px] text-red-700"
                    >
                        <CircleAlert class="size-4 shrink-0" />
                        {{ clientError || fieldError('payment') }}
                    </div>
                    <Button
                        type="submit"
                        class="mt-5 h-11 w-full rounded-xl"
                        :disabled="form.processing"
                    >
                        <Spinner v-if="form.processing" />
                        <HandCoins v-else class="size-4" />
                        ثبت پرداخت
                    </Button>
                </section>
                <section
                    class="rounded-2xl border border-primary/15 bg-primary/[.045] p-4"
                >
                    <p class="text-[10px] leading-6 text-muted-foreground">
                        پرداخت پس از ثبت، بدهی تأمین‌کننده را کاهش می‌دهد و
                        وضعیت فاکتورهای خرید را به‌روزرسانی می‌کند.
                    </p>
                </section>
            </aside>
        </form>
    </div>
</template>
