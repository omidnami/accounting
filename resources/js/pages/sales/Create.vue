<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Boxes,
    Check,
    ChevronLeft,
    CircleAlert,
    Minus,
    Plus,
    Search,
    ShoppingCart,
    Trash2,
    UserRound,
    Warehouse,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import JalaliDatePicker from '@/components/JalaliDatePicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { normalizeNumber, parseNumber } from '@/lib/number';
import lookups from '@/routes/sales/lookups';
import { index as salesIndex, store as storeSale } from '@/routes/sales';

type CustomerOption = {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    credit_limit: string;
    payment_terms: string | null;
};

type ProductOption = {
    id: number;
    code: string;
    barcode: string | null;
    name: string;
    unit: string;
    sale_price: string;
    tax_rate: string;
    stock_quantity: string;
};

type SaleLine = {
    row_id: string;
    product_id: number;
    code: string;
    name: string;
    unit: string;
    stock_quantity: string;
    quantity: string;
    unit_price: string;
    discount_amount: string;
    tax_rate: string;
};

const props = defineProps<{
    defaults: {
        invoice_date: string;
        due_date: string;
        warehouse_id: number | null;
        submission_token: string;
        fiscal_year: {
            title: string;
            starts_on: string;
            ends_on: string;
        };
    };
    warehouses: Array<{
        id: number;
        name: string;
        is_default: boolean;
    }>;
}>();

const form = useForm({
    submission_token: props.defaults.submission_token,
    customer_id: '',
    invoice_date: props.defaults.invoice_date,
    due_date: props.defaults.due_date,
    notes: '',
    items: [] as SaleLine[],
});
const selectedWarehouseId = ref(props.defaults.warehouse_id?.toString() ?? '');
const selectedCustomer = ref<CustomerOption | null>(null);
const customerSearch = ref('');
const customerResults = ref<CustomerOption[]>([]);
const customerLoading = ref(false);
const productSearch = ref('');
const productResults = ref<ProductOption[]>([]);
const productLoading = ref(false);
const lookupError = ref('');
const clientError = ref('');
let customerTimer: ReturnType<typeof setTimeout> | undefined;
let productTimer: ReturnType<typeof setTimeout> | undefined;
let customerRequest = 0;
let productRequest = 0;

const money = new Intl.NumberFormat('fa-IR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});
const quantity = new Intl.NumberFormat('fa-IR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 3,
});
const fieldError = (key: string) =>
    (form.errors as Record<string, string>)[key];
const lineError = (index: number, field: string) =>
    (form.errors as Record<string, string>)[`items.${index}.${field}`];
const lineGross = (line: SaleLine) =>
    parseNumber(line.quantity) * parseNumber(line.unit_price);
const lineTax = (line: SaleLine) =>
    Math.max(
        0,
        ((lineGross(line) - parseNumber(line.discount_amount)) *
            parseNumber(line.tax_rate)) /
            100,
    );
const lineTotal = (line: SaleLine) =>
    Math.max(0, lineGross(line) - parseNumber(line.discount_amount)) +
    lineTax(line);
const totals = computed(() => {
    const subtotal = form.items.reduce((sum, line) => sum + lineGross(line), 0);
    const discount = form.items.reduce(
        (sum, line) => sum + parseNumber(line.discount_amount),
        0,
    );
    const tax = form.items.reduce((sum, line) => sum + lineTax(line), 0);

    return {
        subtotal,
        discount,
        tax,
        total: subtotal - discount + tax,
    };
});
const invalidStockLine = computed(() =>
    form.items.find(
        (line) =>
            parseNumber(line.quantity) <= 0 ||
            parseNumber(line.quantity) > parseNumber(line.stock_quantity),
    ),
);

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
    lookupError.value = '';

    try {
        const response = await getJson<{ data: CustomerOption[] }>(
            lookups.customers.url({
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

const loadProducts = async () => {
    if (!selectedWarehouseId.value) {
        productResults.value = [];
        return;
    }

    const requestId = ++productRequest;
    productLoading.value = true;
    lookupError.value = '';

    try {
        const response = await getJson<{ data: ProductOption[] }>(
            lookups.products.url({
                query: {
                    warehouse_id: Number(selectedWarehouseId.value),
                    search: productSearch.value.trim() || undefined,
                },
            }),
        );

        if (requestId === productRequest) {
            productResults.value = response.data;
        }
    } catch {
        if (requestId === productRequest) {
            lookupError.value = 'دریافت فهرست کالاها انجام نشد.';
        }
    } finally {
        if (requestId === productRequest) {
            productLoading.value = false;
        }
    }
};

watch(customerSearch, () => {
    if (customerTimer) {
        clearTimeout(customerTimer);
    }
    customerTimer = setTimeout(loadCustomers, 250);
});
watch(productSearch, () => {
    if (productTimer) {
        clearTimeout(productTimer);
    }
    productTimer = setTimeout(loadProducts, 250);
});
watch(selectedWarehouseId, () => {
    form.items = [];
    productSearch.value = '';
    productResults.value = [];
    void loadProducts();
});

onMounted(() => {
    void loadCustomers();
    void loadProducts();
});
onBeforeUnmount(() => {
    if (customerTimer) {
        clearTimeout(customerTimer);
    }
    if (productTimer) {
        clearTimeout(productTimer);
    }
});

const selectCustomer = (customer: CustomerOption) => {
    selectedCustomer.value = customer;
    form.customer_id = customer.id.toString();
    customerSearch.value = '';
    customerResults.value = [];
};

const clearCustomer = () => {
    selectedCustomer.value = null;
    form.customer_id = '';
    void loadCustomers();
};

const addProduct = (product: ProductOption) => {
    if (form.items.some((line) => line.product_id === product.id)) {
        productSearch.value = '';
        return;
    }

    form.items.push({
        row_id: `${product.id}-${Date.now()}`,
        product_id: product.id,
        code: product.code,
        name: product.name,
        unit: product.unit,
        stock_quantity: product.stock_quantity,
        quantity: '1',
        unit_price: product.sale_price,
        discount_amount: '0',
        tax_rate: product.tax_rate,
    });
    productSearch.value = '';
    productResults.value = [];
    clientError.value = '';
};

const removeLine = (index: number) => {
    form.items.splice(index, 1);
};

type NumericLineField =
    | 'quantity'
    | 'unit_price'
    | 'discount_amount'
    | 'tax_rate';

const normalizeLineField = (line: SaleLine, field: NumericLineField) => {
    line[field] = normalizeNumber(line[field]);
};

const incrementQuantity = (line: SaleLine) => {
    const next = parseNumber(line.quantity) + 1;
    line.quantity = Number.isInteger(next) ? String(next) : next.toFixed(3);
};

const submit = () => {
    clientError.value = '';

    if (!form.customer_id) {
        clientError.value = 'ابتدا مشتری فاکتور را انتخاب کنید.';
        return;
    }

    if (!form.items.length) {
        clientError.value = 'حداقل یک کالا به فاکتور اضافه کنید.';
        return;
    }

    const invalid = invalidStockLine.value;

    if (invalid) {
        clientError.value = `مقدار «${invalid.name}» باید بیش از صفر و حداکثر ${quantity.format(parseNumber(invalid.stock_quantity))} ${invalid.unit} باشد.`;
        return;
    }

    form.transform((data) => ({
        ...data,
        items: data.items.map((line) => ({
            product_id: line.product_id,
            quantity: normalizeNumber(line.quantity),
            unit_price: normalizeNumber(line.unit_price),
            discount_amount: normalizeNumber(line.discount_amount || '0'),
            tax_rate: normalizeNumber(line.tax_rate || '0'),
        })),
    })).post(storeSale().url, {
        preserveScroll: true,
    });
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'فروش و صندوق',
                href: salesIndex(),
            },
            {
                title: 'فروش جدید',
                href: '/sales/create',
            },
        ],
    },
});
</script>

<template>
    <Head title="فروش جدید" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="relative overflow-hidden rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="relative flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <ShoppingCart class="size-5" />
                        <span class="text-xs font-bold">صندوق فروش</span>
                    </div>
                    <h1 class="text-2xl font-black tracking-tight sm:text-3xl">
                        ساخت پیش‌نویس فاکتور فروش
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        سال مالی {{ defaults.fiscal_year.title }} · تأیید نهایی
                        موجودی، اعتبار مشتری و سند در صفحه فاکتور انجام می‌شود.
                    </p>
                </div>
                <Button as-child variant="outline" class="w-fit rounded-xl">
                    <Link :href="salesIndex()">
                        <ArrowRight class="size-4" />
                        بازگشت
                    </Link>
                </Button>
            </div>
        </section>

        <div
            v-if="lookupError"
            class="flex items-center gap-2 rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-xs text-amber-800 dark:text-amber-200"
        >
            <CircleAlert class="size-4" />
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
                                جست‌وجو با نام، کد، تلفن یا کد ملی
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="selectedCustomer"
                        class="flex items-center justify-between gap-4 rounded-2xl border border-primary/20 bg-primary/[.045] p-4"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground"
                            >
                                <Check class="size-5" />
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold">
                                    {{ selectedCustomer.name }}
                                </p>
                                <p
                                    class="mt-1 text-[10px] text-muted-foreground"
                                >
                                    کد {{ selectedCustomer.code }} · سقف اعتبار
                                    {{
                                        money.format(
                                            Number(
                                                selectedCustomer.credit_limit,
                                            ),
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="clearCustomer"
                            >تغییر</Button
                        >
                    </div>

                    <div v-else class="relative">
                        <Search
                            class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                        />
                        <Input
                            v-model="customerSearch"
                            placeholder="یک مشتری را جست‌وجو کنید..."
                            class="h-11 rounded-xl pr-9"
                        />
                        <div
                            class="mt-2 max-h-64 overflow-y-auto rounded-xl border bg-background shadow-sm empty:hidden"
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
                                class="flex w-full items-center justify-between gap-3 border-b px-4 py-3 text-right last:border-0 hover:bg-muted/40"
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
                                <ChevronLeft class="size-4" />
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
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="invoice_date">تاریخ فاکتور *</Label>
                            <JalaliDatePicker
                                id="invoice_date"
                                v-model="form.invoice_date"
                                :min="defaults.fiscal_year.starts_on"
                                :max="defaults.fiscal_year.ends_on"
                            />
                            <InputError :message="form.errors.invoice_date" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="due_date">سررسید</Label>
                            <JalaliDatePicker
                                id="due_date"
                                v-model="form.due_date"
                                :min="form.invoice_date"
                                placeholder="بدون سررسید"
                            />
                            <InputError :message="form.errors.due_date" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="warehouse_id">انبار فروش *</Label>
                            <div class="relative">
                                <Warehouse
                                    class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                                />
                                <select
                                    id="warehouse_id"
                                    v-model="selectedWarehouseId"
                                    class="h-11 w-full rounded-xl border border-input bg-background pr-9 pl-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                                >
                                    <option value="">انتخاب انبار</option>
                                    <option
                                        v-for="warehouse in warehouses"
                                        :key="warehouse.id"
                                        :value="warehouse.id.toString()"
                                    >
                                        {{ warehouse.name }}
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>
                </section>

                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:p-6"
                >
                    <div class="mb-5 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span
                                class="flex size-10 items-center justify-center rounded-xl bg-sky-500/10 text-sky-600"
                            >
                                <Boxes class="size-5" />
                            </span>
                            <div>
                                <h2 class="text-sm font-bold">اقلام فاکتور</h2>
                                <p
                                    class="mt-1 text-[10px] text-muted-foreground"
                                >
                                    موجودی نمایش‌داده‌شده مربوط به انبار
                                    انتخاب‌شده است.
                                </p>
                            </div>
                        </div>
                        <span
                            class="rounded-full bg-muted px-2.5 py-1 text-[10px] font-semibold"
                            >{{ form.items.length }} قلم</span
                        >
                    </div>

                    <div class="relative mb-5">
                        <Search
                            class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                        />
                        <Input
                            v-model="productSearch"
                            placeholder="نام، کد یا بارکد کالا..."
                            class="h-11 rounded-xl pr-9"
                            :disabled="!selectedWarehouseId"
                        />
                        <div
                            v-if="productSearch.trim()"
                            class="absolute inset-x-0 top-12 z-20 max-h-72 overflow-y-auto rounded-xl border bg-popover shadow-xl"
                        >
                            <p
                                v-if="productLoading"
                                class="px-4 py-3 text-xs text-muted-foreground"
                            >
                                در حال جست‌وجو...
                            </p>
                            <button
                                v-for="product in productResults"
                                :key="product.id"
                                type="button"
                                class="flex w-full items-center justify-between gap-3 border-b px-4 py-3 text-right last:border-0 hover:bg-muted/40"
                                @click="addProduct(product)"
                            >
                                <span class="min-w-0">
                                    <span
                                        class="block truncate text-xs font-semibold"
                                        >{{ product.name }}</span
                                    >
                                    <span
                                        class="mt-1 block text-[10px] text-muted-foreground"
                                        >{{ product.code }} · موجودی
                                        {{
                                            quantity.format(
                                                Number(product.stock_quantity),
                                            )
                                        }}
                                        {{ product.unit }}</span
                                    >
                                </span>
                                <span class="shrink-0 text-xs font-bold">{{
                                    money.format(Number(product.sale_price))
                                }}</span>
                            </button>
                            <p
                                v-if="!productLoading && !productResults.length"
                                class="px-4 py-4 text-xs text-muted-foreground"
                            >
                                کالای فعالی پیدا نشد.
                            </p>
                        </div>
                        <InputError class="mt-2" :message="form.errors.items" />
                    </div>

                    <div v-if="form.items.length" class="space-y-3">
                        <article
                            v-for="(line, index) in form.items"
                            :key="line.row_id"
                            class="rounded-2xl border bg-muted/10 p-4"
                        >
                            <div
                                class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start"
                            >
                                <div class="min-w-0">
                                    <p class="text-sm font-bold">
                                        {{ line.name }}
                                    </p>
                                    <p
                                        class="mt-1 flex flex-wrap gap-2 text-[10px] text-muted-foreground"
                                    >
                                        <span dir="ltr" class="font-mono">{{
                                            line.code
                                        }}</span>
                                        <span>موجودی:</span>
                                        <span class="font-semibold">
                                            {{
                                                quantity.format(
                                                    Number(line.stock_quantity),
                                                )
                                            }}
                                            {{ line.unit }}
                                        </span>
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-red-600"
                                    title="حذف ردیف"
                                    @click="removeLine(index)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>

                            <div
                                class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                            >
                                <div class="grid gap-1.5">
                                    <Label
                                        :for="`quantity-${line.row_id}`"
                                        class="text-[10px]"
                                        >مقدار</Label
                                    >
                                    <div class="flex">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            class="h-10 rounded-l-none rounded-r-xl"
                                            @click="incrementQuantity(line)"
                                        >
                                            <Plus class="size-3.5" />
                                        </Button>
                                        <Input
                                            :id="`quantity-${line.row_id}`"
                                            v-model="line.quantity"
                                            inputmode="decimal"
                                            dir="ltr"
                                            class="h-10 rounded-none text-center"
                                            @blur="
                                                normalizeLineField(
                                                    line,
                                                    'quantity',
                                                )
                                            "
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            class="h-10 rounded-l-xl rounded-r-none"
                                            @click="
                                                line.quantity = String(
                                                    Math.max(
                                                        0,
                                                        parseNumber(
                                                            line.quantity,
                                                        ) - 1,
                                                    ),
                                                )
                                            "
                                        >
                                            <Minus class="size-3.5" />
                                        </Button>
                                    </div>
                                    <InputError
                                        :message="lineError(index, 'quantity')"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label
                                        :for="`price-${line.row_id}`"
                                        class="text-[10px]"
                                        >قیمت واحد</Label
                                    >
                                    <Input
                                        :id="`price-${line.row_id}`"
                                        v-model="line.unit_price"
                                        inputmode="decimal"
                                        dir="ltr"
                                        class="h-10 rounded-xl text-left"
                                        @blur="
                                            normalizeLineField(
                                                line,
                                                'unit_price',
                                            )
                                        "
                                    />
                                    <InputError
                                        :message="
                                            lineError(index, 'unit_price')
                                        "
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label
                                        :for="`discount-${line.row_id}`"
                                        class="text-[10px]"
                                        >تخفیف ردیف</Label
                                    >
                                    <Input
                                        :id="`discount-${line.row_id}`"
                                        v-model="line.discount_amount"
                                        inputmode="decimal"
                                        dir="ltr"
                                        class="h-10 rounded-xl text-left"
                                        @blur="
                                            normalizeLineField(
                                                line,
                                                'discount_amount',
                                            )
                                        "
                                    />
                                    <InputError
                                        :message="
                                            lineError(index, 'discount_amount')
                                        "
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label
                                        :for="`tax-${line.row_id}`"
                                        class="text-[10px]"
                                        >مالیات ٪</Label
                                    >
                                    <Input
                                        :id="`tax-${line.row_id}`"
                                        v-model="line.tax_rate"
                                        inputmode="decimal"
                                        dir="ltr"
                                        class="h-10 rounded-xl text-left"
                                        @blur="
                                            normalizeLineField(line, 'tax_rate')
                                        "
                                    />
                                    <InputError
                                        :message="lineError(index, 'tax_rate')"
                                    />
                                </div>
                            </div>

                            <div
                                class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-dashed pt-3 text-[10px]"
                            >
                                <span class="text-muted-foreground">
                                    تخفیف:
                                    {{
                                        money.format(
                                            parseNumber(line.discount_amount),
                                        )
                                    }}
                                    · مالیات:
                                    {{ money.format(lineTax(line)) }}
                                </span>
                                <span class="font-bold">
                                    جمع ردیف:
                                    {{ money.format(lineTotal(line)) }}
                                </span>
                            </div>
                        </article>
                    </div>

                    <div
                        v-else
                        class="rounded-2xl border border-dashed px-5 py-12 text-center"
                    >
                        <Boxes
                            class="mx-auto size-8 text-muted-foreground/40"
                        />
                        <p class="mt-3 text-xs font-semibold">
                            هنوز کالایی اضافه نشده است
                        </p>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            با جست‌وجوی نام یا بارکد شروع کنید.
                        </p>
                    </div>
                </section>

                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <Label for="notes">یادداشت فاکتور</Label>
                    <textarea
                        id="notes"
                        v-model="form.notes"
                        rows="3"
                        placeholder="توضیح اختیاری برای این فروش"
                        class="mt-2 w-full resize-y rounded-xl border border-input bg-background px-4 py-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                    />
                    <InputError :message="form.errors.notes" />
                </section>
            </div>

            <aside class="space-y-4 xl:sticky xl:top-6">
                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <h2 class="text-sm font-bold">جمع فاکتور</h2>
                    <div class="mt-5 space-y-3 text-xs">
                        <div class="flex justify-between">
                            <span class="text-muted-foreground"
                                >جمع کالاها</span
                            >
                            <span>{{ money.format(totals.subtotal) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">تخفیف</span>
                            <span class="text-red-600"
                                >− {{ money.format(totals.discount) }}</span
                            >
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">مالیات</span>
                            <span>{{ money.format(totals.tax) }}</span>
                        </div>
                        <div
                            class="flex items-end justify-between border-t border-dashed pt-4"
                        >
                            <span class="font-semibold">مبلغ قابل پرداخت</span>
                            <span class="text-xl font-black"
                                >{{ money.format(totals.total) }}
                                <small class="text-[9px] font-medium"
                                    >ریال</small
                                ></span
                            >
                        </div>
                    </div>

                    <div
                        v-if="clientError || fieldError('sale')"
                        class="mt-4 rounded-xl bg-red-500/10 px-3 py-2.5 text-[10px] leading-5 text-red-700 dark:text-red-300"
                    >
                        {{ clientError || fieldError('sale') }}
                    </div>

                    <Button
                        type="submit"
                        class="mt-5 h-11 w-full rounded-xl"
                        :disabled="form.processing"
                    >
                        <Spinner v-if="form.processing" />
                        <ShoppingCart v-else class="size-4" />
                        ثبت پیش‌نویس فروش
                    </Button>
                </section>

                <section
                    class="rounded-2xl border border-primary/15 bg-primary/[.045] p-4"
                >
                    <p class="text-[10px] leading-6 text-muted-foreground">
                        این مرحله فقط پیش‌نویس می‌سازد. خروج انبار، COGS،
                        مطالبات و سند حسابداری هنگام تأیید نهایی و به‌صورت
                        یکپارچه ثبت می‌شوند.
                    </p>
                </section>
            </aside>
        </form>
    </div>
</template>
