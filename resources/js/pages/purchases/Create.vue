<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    Check,
    ChevronLeft,
    CircleAlert,
    Plus,
    Search,
    ShoppingBag,
    Trash2,
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
import lookups from '@/routes/purchases/lookups';
import {
    create as createPurchase,
    index as purchasesIndex,
    store as storePurchase,
} from '@/routes/purchases';

type SupplierOption = {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    payment_terms: string | null;
    lead_time_days: number | null;
};
type ProductOption = {
    id: number;
    code: string;
    name: string;
    unit: string;
    purchase_price: string;
    tax_rate: string;
    stock_quantity: string;
};
type Line = {
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
        fiscal_year: { title: string; starts_on: string; ends_on: string };
    };
    warehouses: Array<{ id: number; name: string; is_default: boolean }>;
}>();

const form = useForm({
    submission_token: props.defaults.submission_token,
    supplier_id: '',
    invoice_date: props.defaults.invoice_date,
    due_date: props.defaults.due_date,
    notes: '',
    items: [] as Line[],
});
const warehouseId = ref(props.defaults.warehouse_id?.toString() ?? '');
const selectedSupplier = ref<SupplierOption | null>(null);
const supplierSearch = ref('');
const suppliers = ref<SupplierOption[]>([]);
const supplierLoading = ref(false);
const productSearch = ref('');
const products = ref<ProductOption[]>([]);
const productLoading = ref(false);
const error = ref('');
let supplierTimer: ReturnType<typeof setTimeout> | undefined;
let productTimer: ReturnType<typeof setTimeout> | undefined;
let supplierRequest = 0;
let productRequest = 0;

const money = new Intl.NumberFormat('fa-IR');
const quantity = new Intl.NumberFormat('fa-IR', {
    maximumFractionDigits: 3,
});
const gross = (line: Line) =>
    parseNumber(line.quantity) * parseNumber(line.unit_price);
const tax = (line: Line) =>
    ((gross(line) - parseNumber(line.discount_amount)) *
        parseNumber(line.tax_rate)) /
    100;
const lineTotal = (line: Line) =>
    gross(line) - parseNumber(line.discount_amount) + tax(line);
const totals = computed(() => {
    const subtotal = form.items.reduce((sum, line) => sum + gross(line), 0);
    const discount = form.items.reduce(
        (sum, line) => sum + parseNumber(line.discount_amount),
        0,
    );
    const taxTotal = form.items.reduce((sum, line) => sum + tax(line), 0);

    return {
        subtotal,
        discount,
        tax: taxTotal,
        total: subtotal - discount + taxTotal,
    };
});
const fieldError = (key: string) =>
    (form.errors as Record<string, string>)[key];
const lineError = (index: number, field: string) =>
    (form.errors as Record<string, string>)[`items.${index}.${field}`];

const getJson = async <T>(url: string): Promise<T> => {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error('lookup');
    }

    return (await response.json()) as T;
};
const loadSuppliers = async () => {
    const requestId = ++supplierRequest;
    supplierLoading.value = true;

    try {
        const response = await getJson<{ data: SupplierOption[] }>(
            lookups.suppliers.url({
                query: { search: supplierSearch.value.trim() || undefined },
            }),
        );

        if (requestId === supplierRequest) {
            suppliers.value = response.data;
        }
    } finally {
        if (requestId === supplierRequest) {
            supplierLoading.value = false;
        }
    }
};
const loadProducts = async () => {
    if (!warehouseId.value) {
        products.value = [];
        return;
    }

    const requestId = ++productRequest;
    productLoading.value = true;

    try {
        const response = await getJson<{ data: ProductOption[] }>(
            lookups.products.url({
                query: {
                    warehouse_id: Number(warehouseId.value),
                    search: productSearch.value.trim() || undefined,
                },
            }),
        );

        if (requestId === productRequest) {
            products.value = response.data;
        }
    } finally {
        if (requestId === productRequest) {
            productLoading.value = false;
        }
    }
};
watch(supplierSearch, () => {
    if (supplierTimer) clearTimeout(supplierTimer);
    supplierTimer = setTimeout(loadSuppliers, 250);
});
watch(productSearch, () => {
    if (productTimer) clearTimeout(productTimer);
    productTimer = setTimeout(loadProducts, 250);
});
watch(warehouseId, () => {
    form.items = [];
    productSearch.value = '';
    products.value = [];
    void loadProducts();
});
onMounted(() => {
    void loadSuppliers();
    void loadProducts();
});
onBeforeUnmount(() => {
    if (supplierTimer) clearTimeout(supplierTimer);
    if (productTimer) clearTimeout(productTimer);
});

const selectSupplier = (supplier: SupplierOption) => {
    selectedSupplier.value = supplier;
    form.supplier_id = supplier.id.toString();
    supplierSearch.value = '';
    suppliers.value = [];
};
const addProduct = (product: ProductOption) => {
    if (form.items.some((line) => line.product_id === product.id)) return;

    form.items.push({
        row_id: `${product.id}-${Date.now()}`,
        product_id: product.id,
        code: product.code,
        name: product.name,
        unit: product.unit,
        stock_quantity: product.stock_quantity,
        quantity: '1',
        unit_price: product.purchase_price,
        discount_amount: '0',
        tax_rate: product.tax_rate,
    });
    productSearch.value = '';
    products.value = [];
};
type Numeric = 'quantity' | 'unit_price' | 'discount_amount' | 'tax_rate';
const normalizeLine = (line: Line, field: Numeric) => {
    line[field] = normalizeNumber(line[field]);
};
const submit = () => {
    error.value = '';

    if (!form.supplier_id) {
        error.value = 'ابتدا تأمین‌کننده را انتخاب کنید.';
        return;
    }
    if (!form.items.length) {
        error.value = 'حداقل یک کالا به فاکتور اضافه کنید.';
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
    })).post(storePurchase().url, { preserveScroll: true });
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'خرید و تأمین', href: purchasesIndex() },
            { title: 'خرید جدید', href: createPurchase() },
        ],
    },
});
</script>

<template>
    <Head title="خرید جدید" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="flex flex-col justify-between gap-4 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <ShoppingBag class="size-5" />
                        <span class="text-xs font-bold">فاکتور خرید</span>
                    </div>
                    <h1 class="text-2xl font-black sm:text-3xl">
                        ساخت پیش‌نویس خرید
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        سال مالی {{ defaults.fiscal_year.title }} · ورود انبار
                        هنگام تأیید نهایی انجام می‌شود.
                    </p>
                </div>
                <Button as-child variant="outline" class="rounded-xl">
                    <Link :href="purchasesIndex()">
                        <ArrowRight class="size-4" /> بازگشت
                    </Link>
                </Button>
            </div>
        </section>

        <form
            class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_340px]"
            @submit.prevent="submit"
        >
            <div class="space-y-6">
                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="mb-4 text-sm font-bold">تأمین‌کننده</h2>
                    <div
                        v-if="selectedSupplier"
                        class="flex items-center justify-between rounded-2xl border border-primary/20 bg-primary/[.045] p-4"
                    >
                        <div>
                            <p class="text-sm font-bold">
                                {{ selectedSupplier.name }}
                            </p>
                            <p class="mt-1 text-[10px] text-muted-foreground">
                                {{ selectedSupplier.code }} ·
                                {{
                                    selectedSupplier.payment_terms ??
                                    'بدون شرایط پرداخت'
                                }}
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="
                                selectedSupplier = null;
                                form.supplier_id = '';
                            "
                            >تغییر</Button
                        >
                    </div>
                    <div v-else class="relative">
                        <Search
                            class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                        />
                        <Input
                            v-model="supplierSearch"
                            placeholder="جست‌وجوی تأمین‌کننده..."
                            class="h-11 rounded-xl pr-9"
                        />
                        <div
                            class="mt-2 max-h-60 overflow-y-auto rounded-xl border bg-background"
                        >
                            <p
                                v-if="supplierLoading"
                                class="px-4 py-3 text-xs text-muted-foreground"
                            >
                                در حال جست‌وجو...
                            </p>
                            <button
                                v-for="supplier in suppliers"
                                :key="supplier.id"
                                type="button"
                                class="flex w-full justify-between border-b px-4 py-3 text-right last:border-0 hover:bg-muted/40"
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
                                <ChevronLeft class="size-4" />
                            </button>
                        </div>
                        <InputError
                            class="mt-2"
                            :message="form.errors.supplier_id"
                        />
                    </div>
                </section>

                <section
                    class="grid gap-4 rounded-2xl border bg-card p-5 sm:grid-cols-3"
                >
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
                        <Label for="warehouse">انبار ورود *</Label>
                        <select
                            id="warehouse"
                            v-model="warehouseId"
                            class="h-11 rounded-xl border bg-background px-3 text-sm"
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
                </section>

                <section class="rounded-2xl border bg-card p-5">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-sm font-bold">اقلام خرید</h2>
                        <span
                            class="rounded-full bg-muted px-2.5 py-1 text-[10px]"
                            >{{ form.items.length }} قلم</span
                        >
                    </div>
                    <div class="relative mb-4">
                        <Search
                            class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                        />
                        <Input
                            v-model="productSearch"
                            placeholder="نام، کد یا بارکد کالا..."
                            class="h-11 rounded-xl pr-9"
                            :disabled="!warehouseId"
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
                                v-for="product in products"
                                :key="product.id"
                                type="button"
                                class="flex w-full justify-between border-b px-4 py-3 text-right last:border-0 hover:bg-muted/40"
                                @click="addProduct(product)"
                            >
                                <span>
                                    <span class="block text-xs font-semibold">{{
                                        product.name
                                    }}</span>
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
                                <span class="text-xs font-bold">{{
                                    money.format(Number(product.purchase_price))
                                }}</span>
                            </button>
                        </div>
                    </div>

                    <div v-if="form.items.length" class="space-y-3">
                        <article
                            v-for="(line, index) in form.items"
                            :key="line.row_id"
                            class="rounded-2xl border bg-muted/10 p-4"
                        >
                            <div class="flex justify-between">
                                <div>
                                    <p class="text-sm font-bold">
                                        {{ line.name }}
                                    </p>
                                    <p
                                        class="mt-1 text-[10px] text-muted-foreground"
                                    >
                                        {{ line.code }} · موجودی فعلی
                                        {{
                                            quantity.format(
                                                Number(line.stock_quantity),
                                            )
                                        }}
                                        {{ line.unit }}
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-red-600"
                                    @click="form.items.splice(index, 1)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                            <div
                                class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                            >
                                <div class="grid gap-1.5">
                                    <Label class="text-[10px]">مقدار</Label>
                                    <Input
                                        v-model="line.quantity"
                                        inputmode="decimal"
                                        dir="ltr"
                                        class="h-10 text-left"
                                        @blur="normalizeLine(line, 'quantity')"
                                    />
                                    <InputError
                                        :message="lineError(index, 'quantity')"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label class="text-[10px]">قیمت خرید</Label>
                                    <Input
                                        v-model="line.unit_price"
                                        inputmode="decimal"
                                        dir="ltr"
                                        class="h-10 text-left"
                                        @blur="
                                            normalizeLine(line, 'unit_price')
                                        "
                                    />
                                    <InputError
                                        :message="
                                            lineError(index, 'unit_price')
                                        "
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label class="text-[10px]">تخفیف</Label>
                                    <Input
                                        v-model="line.discount_amount"
                                        inputmode="decimal"
                                        dir="ltr"
                                        class="h-10 text-left"
                                        @blur="
                                            normalizeLine(
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
                                    <Label class="text-[10px]">مالیات ٪</Label>
                                    <Input
                                        v-model="line.tax_rate"
                                        inputmode="decimal"
                                        dir="ltr"
                                        class="h-10 text-left"
                                        @blur="normalizeLine(line, 'tax_rate')"
                                    />
                                    <InputError
                                        :message="lineError(index, 'tax_rate')"
                                    />
                                </div>
                            </div>
                            <div
                                class="mt-3 flex justify-between border-t border-dashed pt-3 text-[10px]"
                            >
                                <span class="text-muted-foreground"
                                    >مالیات: {{ money.format(tax(line)) }}</span
                                >
                                <span class="font-bold"
                                    >جمع:
                                    {{ money.format(lineTotal(line)) }}</span
                                >
                            </div>
                        </article>
                    </div>
                    <div
                        v-else
                        class="rounded-2xl border border-dashed py-10 text-center text-xs text-muted-foreground"
                    >
                        هنوز کالایی اضافه نشده است.
                    </div>
                    <InputError class="mt-2" :message="form.errors.items" />
                </section>

                <section class="rounded-2xl border bg-card p-5">
                    <Label for="notes">یادداشت خرید</Label>
                    <textarea
                        id="notes"
                        v-model="form.notes"
                        rows="3"
                        class="mt-2 w-full rounded-xl border px-4 py-3 text-sm"
                    />
                </section>
            </div>

            <aside class="space-y-4 xl:sticky xl:top-6">
                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="text-sm font-bold">جمع فاکتور خرید</h2>
                    <div class="mt-5 space-y-3 text-xs">
                        <div class="flex justify-between">
                            <span>جمع کالاها</span>
                            <span>{{ money.format(totals.subtotal) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>تخفیف</span>
                            <span class="text-red-600"
                                >− {{ money.format(totals.discount) }}</span
                            >
                        </div>
                        <div class="flex justify-between">
                            <span>مالیات</span>
                            <span>{{ money.format(totals.tax) }}</span>
                        </div>
                        <div
                            class="flex justify-between border-t border-dashed pt-4 text-lg font-black"
                        >
                            <span>مبلغ نهایی</span>
                            <span
                                >{{ money.format(totals.total) }}
                                <small class="text-[9px]">ریال</small></span
                            >
                        </div>
                    </div>
                    <div
                        v-if="error || fieldError('purchase')"
                        class="mt-4 flex gap-2 rounded-xl bg-red-500/10 p-3 text-[10px] text-red-700"
                    >
                        <CircleAlert class="size-4 shrink-0" />
                        {{ error || fieldError('purchase') }}
                    </div>
                    <Button
                        type="submit"
                        class="mt-5 h-11 w-full rounded-xl"
                        :disabled="form.processing"
                    >
                        <Spinner v-if="form.processing" />
                        <Plus v-else class="size-4" />
                        ثبت پیش‌نویس خرید
                    </Button>
                </section>
                <section
                    class="rounded-2xl border border-primary/15 bg-primary/[.045] p-4"
                >
                    <p class="text-[10px] leading-6 text-muted-foreground">
                        تأیید نهایی موجودی انبار، بدهی تأمین‌کننده و سند خرید را
                        یکجا ثبت می‌کند.
                    </p>
                </section>
            </aside>
        </form>
    </div>
</template>
