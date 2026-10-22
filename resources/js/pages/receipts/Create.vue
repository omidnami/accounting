<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Banknote,
    CalendarDays,
    Check,
    CircleAlert,
    CreditCard,
    HandCoins,
    Landmark,
    Search,
    UserRound,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { normalizeNumber, parseNumber } from '@/lib/number';
import { customers as customerLookup } from '@/routes/sales/lookups';
import { invoices as invoiceLookup } from '@/routes/receipts/lookups';
import {
    create as createReceipt,
    index as receiptsIndex,
    store as storeReceipt,
} from '@/routes/receipts';

type CustomerOption = {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    credit_limit: string;
};

type OpenInvoice = {
    id: number;
    invoice_number: string;
    invoice_date: string;
    due_date: string | null;
    status: 'confirmed' | 'partially_paid';
    total: string;
    outstanding: string;
};

type Allocation = OpenInvoice & {
    amount: string;
};

type TreasuryOption = {
    id: number;
    name: string;
    type: 'cash' | 'bank';
    is_default: boolean;
};

type PaymentMethod = 'cash' | 'bank' | 'card';

const props = defineProps<{
    defaults: {
        receipt_date: string;
        submission_token: string;
    };
    fiscal_year: {
        title: string;
        starts_on: string;
        ends_on: string;
    };
    initial_customer: CustomerOption | null;
    initial_invoices: OpenInvoice[];
    initial_invoice_id: number | null;
    treasury_accounts: TreasuryOption[];
}>();

const initialAllocations =
    props.initial_invoice_id === null
        ? []
        : props.initial_invoices
              .filter((invoice) => invoice.id === props.initial_invoice_id)
              .map((invoice) => ({
                  ...invoice,
                  amount: invoice.outstanding,
              }));

const form = useForm({
    submission_token: props.defaults.submission_token,
    customer_id: props.initial_customer?.id.toString() ?? '',
    receipt_date: props.defaults.receipt_date,
    payment_method: 'cash' as PaymentMethod,
    treasury_account_id: '',
    reference: '',
    description: '',
    allocations: initialAllocations as Allocation[],
});
const selectedCustomer = ref<CustomerOption | null>(props.initial_customer);
const customerSearch = ref('');
const customerResults = ref<CustomerOption[]>([]);
const customerLoading = ref(false);
const openInvoices = ref<OpenInvoice[]>(props.initial_invoices);
const invoiceLoading = ref(false);
const lookupError = ref('');
const clientError = ref('');
let customerTimer: ReturnType<typeof setTimeout> | undefined;
let customerRequest = 0;

const money = new Intl.NumberFormat('fa-IR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});
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
const eligibleAccounts = computed(() => {
    const type = form.payment_method === 'cash' ? 'cash' : 'bank';

    return props.treasury_accounts.filter((account) => account.type === type);
});
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

    if (!response.ok) {
        throw new Error('lookup-failed');
    }

    return (await response.json()) as T;
};

const loadCustomers = async () => {
    const requestId = ++customerRequest;
    customerLoading.value = true;

    try {
        const response = await getJson<{ data: CustomerOption[] }>(
            customerLookup.url({
                query: { search: customerSearch.value.trim() || undefined },
            }),
        );

        if (requestId === customerRequest) {
            customerResults.value = response.data;
        }
    } catch {
        if (requestId === customerRequest) {
            lookupError.value = 'دریافت فهرست مشتریان انجام نشد.';
        }
    } finally {
        if (requestId === customerRequest) {
            customerLoading.value = false;
        }
    }
};

const loadInvoices = async () => {
    if (!form.customer_id) {
        openInvoices.value = [];
        return;
    }

    invoiceLoading.value = true;
    lookupError.value = '';

    try {
        const response = await getJson<{
            customer: CustomerOption;
            invoices: OpenInvoice[];
        }>(
            invoiceLookup.url({
                query: { customer_id: Number(form.customer_id) },
            }),
        );
        selectedCustomer.value = response.customer;
        openInvoices.value = response.invoices;
    } catch {
        lookupError.value = 'دریافت فاکتورهای باز انجام نشد.';
    } finally {
        invoiceLoading.value = false;
    }
};

watch(customerSearch, () => {
    if (customerTimer) {
        clearTimeout(customerTimer);
    }
    customerTimer = setTimeout(loadCustomers, 250);
});
watch(
    () => form.payment_method,
    () => {
        const defaultAccount = eligibleAccounts.value.find(
            (account) => account.is_default,
        );
        form.treasury_account_id =
            defaultAccount?.id.toString() ??
            eligibleAccounts.value[0]?.id.toString() ??
            '';
    },
);

onMounted(() => {
    if (!selectedCustomer.value) {
        void loadCustomers();
    }
    const defaultAccount = eligibleAccounts.value.find(
        (account) => account.is_default,
    );
    form.treasury_account_id =
        defaultAccount?.id.toString() ??
        eligibleAccounts.value[0]?.id.toString() ??
        '';
});
onBeforeUnmount(() => {
    if (customerTimer) {
        clearTimeout(customerTimer);
    }
});

const selectCustomer = async (customer: CustomerOption) => {
    selectedCustomer.value = customer;
    form.customer_id = customer.id.toString();
    form.allocations = [];
    customerSearch.value = '';
    customerResults.value = [];
    await loadInvoices();
};

const clearCustomer = () => {
    selectedCustomer.value = null;
    form.customer_id = '';
    form.allocations = [];
    openInvoices.value = [];
    void loadCustomers();
};

const toggleInvoice = (invoice: OpenInvoice) => {
    const index = form.allocations.findIndex(
        (allocation) => allocation.id === invoice.id,
    );

    if (index >= 0) {
        form.allocations.splice(index, 1);
        return;
    }

    form.allocations.push({
        ...invoice,
        amount: invoice.outstanding,
    });
};

const isSelected = (invoiceId: number) =>
    form.allocations.some((allocation) => allocation.id === invoiceId);

const submit = () => {
    clientError.value = '';

    if (!form.customer_id) {
        clientError.value = 'ابتدا مشتری را انتخاب کنید.';
        return;
    }

    if (!form.allocations.length) {
        clientError.value = 'حداقل یک فاکتور را برای دریافت انتخاب کنید.';
        return;
    }

    const invalid = form.allocations.find((allocation) => {
        const amount = parseNumber(allocation.amount);

        return amount <= 0 || amount > parseNumber(allocation.outstanding);
    });

    if (invalid) {
        clientError.value = `مبلغ فاکتور ${invalid.invoice_number} باید بیش از صفر و حداکثر ${money.format(parseNumber(invalid.outstanding))} باشد.`;
        return;
    }

    form.transform((data) => ({
        ...data,
        allocations: data.allocations.map((allocation) => ({
            invoice_id: allocation.id,
            amount: normalizeNumber(allocation.amount),
        })),
    })).post(storeReceipt().url, {
        preserveScroll: true,
    });
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'دریافت‌ها',
                href: receiptsIndex(),
            },
            {
                title: 'دریافت جدید',
                href: createReceipt(),
            },
        ],
    },
});
</script>

<template>
    <Head title="دریافت جدید" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="relative overflow-hidden rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="relative flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <HandCoins class="size-5" />
                        <span class="text-xs font-bold">دریافت از مشتری</span>
                    </div>
                    <h1 class="text-2xl font-black tracking-tight sm:text-3xl">
                        ثبت دریافت و تسویه فاکتور
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        سال مالی {{ fiscal_year.title }} · دریافت پس از ثبت، سند
                        خزانه و کاهش مطالبات را ایجاد می‌کند.
                    </p>
                </div>
                <Button as-child variant="outline" class="rounded-xl">
                    <Link :href="receiptsIndex()">
                        <ArrowRight class="size-4" />
                        بازگشت
                    </Link>
                </Button>
            </div>
        </section>

        <div
            v-if="lookupError"
            class="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-xs text-amber-800 dark:text-amber-200"
        >
            {{ lookupError }}
        </div>

        <form
            class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_350px]"
            @submit.prevent="submit"
        >
            <div class="space-y-6">
                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:p-6"
                >
                    <div class="mb-5 flex items-center gap-3">
                        <span
                            class="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary"
                        >
                            <UserRound class="size-5" />
                        </span>
                        <div>
                            <h2 class="text-sm font-bold">مشتری</h2>
                            <p class="mt-1 text-[10px] text-muted-foreground">
                                فقط فاکتورهای باز همین مشتری قابل انتخاب‌اند.
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="selectedCustomer"
                        class="flex items-center justify-between rounded-2xl border border-primary/20 bg-primary/[.045] p-4"
                    >
                        <div>
                            <p class="text-sm font-bold">
                                {{ selectedCustomer.name }}
                            </p>
                            <p class="mt-1 text-[10px] text-muted-foreground">
                                {{ selectedCustomer.code }} ·
                                {{ selectedCustomer.phone ?? 'بدون تلفن' }}
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="clearCustomer"
                            >تغییر</Button
                        >
                    </div>

                    <div v-else>
                        <div class="relative">
                            <Search
                                class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                            />
                            <Input
                                v-model="customerSearch"
                                placeholder="جست‌وجوی مشتری..."
                                class="h-11 rounded-xl pr-9"
                            />
                        </div>
                        <div
                            class="mt-2 max-h-60 overflow-y-auto rounded-xl border bg-background"
                        >
                            <p
                                v-if="customerLoading"
                                class="px-4 py-3 text-xs text-muted-foreground"
                            >
                                در حال جست‌وجو...
                            </p>
                            <button
                                v-for="customer in customerResults"
                                :key="customer.id"
                                type="button"
                                class="flex w-full justify-between border-b px-4 py-3 text-right last:border-0 hover:bg-muted/40"
                                @click="selectCustomer(customer)"
                            >
                                <span>
                                    <span class="block text-xs font-semibold">{{
                                        customer.name
                                    }}</span>
                                    <span
                                        class="mt-1 block text-[10px] text-muted-foreground"
                                        >{{ customer.code }} ·
                                        {{
                                            customer.phone ?? 'بدون تلفن'
                                        }}</span
                                    >
                                </span>
                                <Check class="size-4 text-primary" />
                            </button>
                            <p
                                v-if="
                                    !customerLoading && !customerResults.length
                                "
                                class="px-4 py-4 text-xs text-muted-foreground"
                            >
                                مشتری فعالی پیدا نشد.
                            </p>
                        </div>
                        <InputError
                            class="mt-2"
                            :message="form.errors.customer_id"
                        />
                    </div>
                </section>

                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:p-6"
                >
                    <h2 class="text-sm font-bold">فاکتورهای باز</h2>
                    <p class="mt-1 text-[10px] text-muted-foreground">
                        مبلغ هر تخصیص نمی‌تواند از مانده فاکتور بیشتر باشد.
                    </p>

                    <div
                        v-if="invoiceLoading"
                        class="py-10 text-center text-xs text-muted-foreground"
                    >
                        در حال دریافت مانده فاکتورها...
                    </div>
                    <div v-else-if="openInvoices.length" class="mt-5 space-y-3">
                        <article
                            v-for="invoice in openInvoices"
                            :key="invoice.id"
                            class="rounded-2xl border p-4 transition"
                            :class="
                                isSelected(invoice.id)
                                    ? 'border-primary/30 bg-primary/[.035]'
                                    : 'bg-background'
                            "
                        >
                            <button
                                type="button"
                                class="flex w-full items-center justify-between gap-3 text-right"
                                @click="toggleInvoice(invoice)"
                            >
                                <span>
                                    <span
                                        dir="ltr"
                                        class="block text-right font-mono text-xs font-bold"
                                        >{{ invoice.invoice_number }}</span
                                    >
                                    <span
                                        class="mt-1 block text-[10px] text-muted-foreground"
                                        >تاریخ
                                        {{
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
                                <span
                                    class="flex size-6 items-center justify-center rounded-full border"
                                    :class="
                                        isSelected(invoice.id)
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-muted-foreground/30'
                                    "
                                >
                                    <Check
                                        v-if="isSelected(invoice.id)"
                                        class="size-3.5"
                                    />
                                </span>
                            </button>

                            <div
                                v-if="isSelected(invoice.id)"
                                class="mt-4 grid gap-2"
                            >
                                <Label
                                    :for="`allocation-${invoice.id}`"
                                    class="text-[10px]"
                                    >مبلغ تخصیص</Label
                                >
                                <Input
                                    :id="`allocation-${invoice.id}`"
                                    v-model="
                                        form.allocations.find(
                                            (allocation) =>
                                                allocation.id === invoice.id,
                                        )!.amount
                                    "
                                    inputmode="decimal"
                                    dir="ltr"
                                    class="h-10 rounded-xl text-left"
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
                        v-else-if="selectedCustomer"
                        class="mt-5 rounded-2xl border border-dashed px-5 py-10 text-center"
                    >
                        <HandCoins
                            class="mx-auto size-8 text-muted-foreground/40"
                        />
                        <p class="mt-3 text-xs font-semibold">
                            فاکتور باز قابل تسویه‌ای وجود ندارد
                        </p>
                    </div>
                    <div
                        v-else
                        class="mt-5 rounded-2xl border border-dashed px-5 py-10 text-center text-xs text-muted-foreground"
                    >
                        ابتدا مشتری را انتخاب کنید.
                    </div>
                    <InputError
                        class="mt-2"
                        :message="form.errors.allocations"
                    />
                </section>

                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:p-6"
                >
                    <h2 class="text-sm font-bold">اطلاعات دریافت</h2>
                    <div class="mt-5 grid gap-5 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="receipt_date">تاریخ دریافت *</Label>
                            <div class="relative">
                                <CalendarDays
                                    class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                                />
                                <Input
                                    id="receipt_date"
                                    v-model="form.receipt_date"
                                    type="date"
                                    :min="fiscal_year.starts_on"
                                    :max="fiscal_year.ends_on"
                                    class="h-11 rounded-xl pr-9"
                                />
                            </div>
                            <InputError :message="form.errors.receipt_date" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="reference">کد رهگیری / مرجع</Label>
                            <Input
                                id="reference"
                                v-model="form.reference"
                                placeholder="شماره پیگیری یا مرجع"
                                class="h-11 rounded-xl"
                            />
                            <InputError :message="form.errors.reference" />
                        </div>
                    </div>

                    <div class="mt-5">
                        <Label>روش دریافت *</Label>
                        <div class="mt-2 grid gap-3 sm:grid-cols-3">
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
                                ] as const"
                                :key="method.value"
                                type="button"
                                class="rounded-2xl border p-3 text-right transition"
                                :class="
                                    form.payment_method === method.value
                                        ? 'border-primary/40 bg-primary/[.055] ring-2 ring-primary/10'
                                        : 'bg-background'
                                "
                                @click="form.payment_method = method.value"
                            >
                                <component
                                    :is="method.icon"
                                    class="mb-2 size-5 text-primary"
                                />
                                <span class="block text-xs font-semibold">{{
                                    method.label
                                }}</span>
                            </button>
                        </div>
                        <InputError
                            class="mt-2"
                            :message="form.errors.payment_method"
                        />
                    </div>

                    <div class="mt-5 grid gap-2">
                        <Label for="treasury_account_id"
                            >حساب خزانه (اختیاری)</Label
                        >
                        <select
                            id="treasury_account_id"
                            v-model="form.treasury_account_id"
                            class="h-11 rounded-xl border border-input bg-background px-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                        >
                            <option value="">استفاده از حساب پیش‌فرض</option>
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
                            class="rounded-xl border border-input bg-background px-4 py-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                </section>
            </div>

            <aside class="space-y-4 xl:sticky xl:top-6">
                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <h2 class="text-sm font-bold">خلاصه دریافت</h2>
                    <div class="mt-5 flex items-end justify-between">
                        <span class="text-xs text-muted-foreground"
                            >مبلغ کل</span
                        >
                        <span class="text-2xl font-black"
                            >{{ money.format(total) }}
                            <small class="text-[9px] font-medium"
                                >ریال</small
                            ></span
                        >
                    </div>
                    <p class="mt-2 text-[10px] text-muted-foreground">
                        {{ form.allocations.length }} فاکتور انتخاب شده است.
                    </p>

                    <div
                        v-if="clientError || fieldError('receipt')"
                        class="mt-4 flex items-start gap-2 rounded-xl bg-red-500/10 px-3 py-2 text-[10px] leading-5 text-red-700 dark:text-red-300"
                    >
                        <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
                        {{ clientError || fieldError('receipt') }}
                    </div>

                    <Button
                        type="submit"
                        class="mt-5 h-11 w-full rounded-xl"
                        :disabled="form.processing"
                    >
                        <Spinner v-if="form.processing" />
                        <HandCoins v-else class="size-4" />
                        ثبت دریافت و تسویه
                    </Button>
                </section>

                <section
                    class="rounded-2xl border border-primary/15 bg-primary/[.045] p-4"
                >
                    <p class="text-[10px] leading-6 text-muted-foreground">
                        ثبت دریافت، سند بدهکار حساب خزانه و بستانکار مطالبات را
                        ایجاد می‌کند. دریافت پس از ثبت قابل ویرایش یا حذف نیست.
                    </p>
                </section>
            </aside>
        </form>
    </div>
</template>
