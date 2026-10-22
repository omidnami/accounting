<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Boxes,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    CircleOff,
    FilterX,
    PackagePlus,
    PencilLine,
    Search,
    Warehouse,
} from '@lucide/vue';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import {
    create as createProduct,
    edit as editProduct,
    index as productsIndex,
} from '@/routes/products';

type StockStatus = 'in_stock' | 'low_stock' | 'out_of_stock';

type ProductRow = {
    id: number;
    code: string;
    barcode: string | null;
    name: string;
    unit: string;
    purchase_price: string;
    sale_price: string;
    tax_rate: string;
    reorder_level: string;
    is_active: boolean;
    total_quantity: string;
    average_cost: string;
    inventory_value: string;
    stock_status: StockStatus;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type Props = {
    products: {
        data: ProductRow[];
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: PaginationLink[];
    };
    filters: {
        search: string;
        stock_status: StockStatus | null;
        status: 'active' | 'inactive' | null;
        warehouse_id: number | null;
    };
    warehouses: Array<{
        id: number;
        name: string;
        is_default: boolean;
    }>;
    summary: {
        total: number;
        in_stock: number;
        low_stock: number;
        out_of_stock: number;
        inventory_value: string;
    };
};

const props = defineProps<Props>();
const filters = reactive({
    search: props.filters.search,
    stock_status: props.filters.stock_status ?? '',
    status: props.filters.status ?? '',
    warehouse_id: props.filters.warehouse_id?.toString() ?? '',
});
const count = new Intl.NumberFormat('fa-IR');
const quantity = new Intl.NumberFormat('fa-IR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 3,
});
const money = new Intl.NumberFormat('fa-IR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});
const stockMeta: Record<
    StockStatus,
    { label: string; className: string; icon: typeof CircleCheck }
> = {
    in_stock: {
        label: 'موجود',
        className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        icon: CircleCheck,
    },
    low_stock: {
        label: 'کم‌موجود',
        className: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
        icon: AlertTriangle,
    },
    out_of_stock: {
        label: 'ناموجود',
        className: 'bg-red-500/10 text-red-700 dark:text-red-300',
        icon: CircleOff,
    },
};
const paginationLabel = (label: string) =>
    /^\d+$/.test(label) ? count.format(Number(label)) : '…';

const applyFilters = () => {
    router.get(
        productsIndex(),
        {
            search: filters.search.trim() || undefined,
            stock_status: filters.stock_status || undefined,
            status: filters.status || undefined,
            warehouse_id: filters.warehouse_id || undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const resetFilters = () => {
    filters.search = '';
    filters.stock_status = '';
    filters.status = '';
    filters.warehouse_id = '';
    router.get(
        productsIndex(),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const goToPage = (url: string | null) => {
    if (url) {
        router.visit(url, {
            preserveState: true,
            preserveScroll: true,
        });
    }
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'کالا و موجودی',
                href: productsIndex(),
            },
        ],
    },
});
</script>

<template>
    <Head title="کالا و موجودی" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="relative overflow-hidden rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="pointer-events-none absolute -top-24 left-8 size-64 rounded-full bg-primary/10 blur-3xl"
            />
            <div
                class="relative flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <Boxes class="size-5" />
                        <span class="text-xs font-bold"
                            >مرکز کالا و موجودی</span
                        >
                    </div>
                    <h1 class="text-2xl font-black tracking-tight sm:text-3xl">
                        کاتالوگ فروشگاه، متصل به موجودی واقعی
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        قیمت‌ها و مشخصات کالا را مدیریت کنید و موجودی را بر اساس
                        دفتر حرکات انبار ببینید.
                    </p>
                </div>
                <Button as-child class="h-11 rounded-xl px-5 shadow-md">
                    <Link :href="createProduct()">
                        <PackagePlus class="size-4" />
                        کالای جدید
                    </Link>
                </Button>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article
                class="rounded-2xl border border-border/70 bg-card p-4 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <p class="text-xs text-muted-foreground">کل کالاها</p>
                    <Boxes class="size-4 text-primary" />
                </div>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.total) }}
                </p>
                <p class="mt-2 text-[10px] text-muted-foreground">
                    {{ count.format(summary.out_of_stock) }} کالای ناموجود
                </p>
            </article>
            <article
                class="rounded-2xl border border-border/70 bg-card p-4 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <p class="text-xs text-muted-foreground">دارای موجودی</p>
                    <CircleCheck class="size-4 text-emerald-600" />
                </div>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.in_stock) }}
                </p>
                <p class="mt-2 text-[10px] text-muted-foreground">
                    در محدوده انتخاب‌شده
                </p>
            </article>
            <article
                class="rounded-2xl border border-border/70 bg-card p-4 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <p class="text-xs text-muted-foreground">
                        کالاهای کم‌موجود
                    </p>
                    <AlertTriangle class="size-4 text-amber-600" />
                </div>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.low_stock) }}
                </p>
                <p class="mt-2 text-[10px] text-muted-foreground">
                    نیازمند بررسی سفارش
                </p>
            </article>
            <article
                class="rounded-2xl border border-border/70 bg-card p-4 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <p class="text-xs text-muted-foreground">ارزش موجودی</p>
                    <Warehouse class="size-4 text-sky-600" />
                </div>
                <p class="mt-3 text-lg font-black sm:text-xl">
                    {{ money.format(Number(summary.inventory_value)) }}
                    <span class="text-[10px] font-medium text-muted-foreground"
                        >ریال</span
                    >
                </p>
                <p class="mt-2 text-[10px] text-muted-foreground">
                    به بهای میانگین موزون
                </p>
            </article>
        </section>

        <form
            class="grid gap-3 rounded-2xl border border-border/70 bg-card p-4 shadow-sm lg:grid-cols-[minmax(0,1.5fr)_1fr_1fr_1fr_auto]"
            @submit.prevent="applyFilters"
        >
            <label class="relative block">
                <span class="sr-only">جست‌وجوی کالا</span>
                <Search
                    class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                />
                <input
                    v-model="filters.search"
                    type="search"
                    placeholder="جست‌وجو با نام، کد یا بارکد..."
                    class="h-10 w-full rounded-xl border border-input bg-background py-2 pr-9 pl-3 text-sm transition outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                />
            </label>
            <label>
                <span class="sr-only">انبار</span>
                <select
                    v-model="filters.warehouse_id"
                    class="h-10 w-full rounded-xl border border-input bg-background px-3 text-sm transition outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                >
                    <option value="">همه انبارها</option>
                    <option
                        v-for="warehouse in warehouses"
                        :key="warehouse.id"
                        :value="warehouse.id.toString()"
                    >
                        {{ warehouse.name }}
                    </option>
                </select>
            </label>
            <label>
                <span class="sr-only">وضعیت موجودی</span>
                <select
                    v-model="filters.stock_status"
                    class="h-10 w-full rounded-xl border border-input bg-background px-3 text-sm transition outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                >
                    <option value="">همه موجودی‌ها</option>
                    <option value="in_stock">موجود</option>
                    <option value="low_stock">کم‌موجود</option>
                    <option value="out_of_stock">ناموجود</option>
                </select>
            </label>
            <label>
                <span class="sr-only">وضعیت کالا</span>
                <select
                    v-model="filters.status"
                    class="h-10 w-full rounded-xl border border-input bg-background px-3 text-sm transition outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                >
                    <option value="">همه وضعیت‌ها</option>
                    <option value="active">فعال</option>
                    <option value="inactive">غیرفعال</option>
                </select>
            </label>
            <div class="flex gap-2">
                <Button type="submit" class="h-10 flex-1 rounded-xl px-4">
                    <Search class="size-4" />
                    فیلتر
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    class="size-10 rounded-xl"
                    title="پاک‌کردن فیلترها"
                    @click="resetFilters"
                >
                    <FilterX class="size-4" />
                </Button>
            </div>
        </form>

        <section
            class="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
        >
            <div
                class="flex flex-col gap-2 border-b border-border/70 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h2 class="text-sm font-bold">فهرست کالاها</h2>
                    <p class="mt-1 text-[11px] text-muted-foreground">
                        {{ count.format(products.total) }} نتیجه
                    </p>
                </div>
                <p class="text-[11px] text-muted-foreground">
                    موجودی فقط از خرید، فروش، مرجوعی و انتقال تغییر می‌کند.
                </p>
            </div>

            <div v-if="products.data.length" class="overflow-x-auto">
                <table class="w-full min-w-[1000px] text-right">
                    <thead
                        class="bg-muted/35 text-[11px] text-muted-foreground"
                    >
                        <tr>
                            <th class="px-5 py-3 font-semibold">کالا</th>
                            <th class="px-4 py-3 font-semibold">قیمت‌ها</th>
                            <th class="px-4 py-3 font-semibold">موجودی</th>
                            <th class="px-4 py-3 font-semibold">ارزش انبار</th>
                            <th class="px-4 py-3 font-semibold">وضعیت</th>
                            <th class="px-5 py-3 text-left font-semibold">
                                عملیات
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        <tr
                            v-for="product in products.data"
                            :key="product.id"
                            class="transition-colors hover:bg-muted/20"
                        >
                            <td class="px-5 py-4">
                                <div>
                                    <p class="text-sm font-bold">
                                        {{ product.name }}
                                    </p>
                                    <div
                                        class="mt-1 flex flex-wrap items-center gap-2 text-[10px] text-muted-foreground"
                                    >
                                        <span
                                            dir="ltr"
                                            class="rounded-md bg-muted px-1.5 py-0.5 font-mono"
                                            >{{ product.code }}</span
                                        >
                                        <span v-if="product.barcode">
                                            بارکد:
                                            <span dir="ltr" class="font-mono">{{
                                                product.barcode
                                            }}</span>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <div class="space-y-1 text-[11px]">
                                    <p>
                                        فروش:
                                        <span class="font-semibold">{{
                                            money.format(
                                                Number(product.sale_price),
                                            )
                                        }}</span>
                                    </p>
                                    <p class="text-muted-foreground">
                                        خرید:
                                        {{
                                            money.format(
                                                Number(product.purchase_price),
                                            )
                                        }}
                                    </p>
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <p class="text-sm font-black">
                                    {{
                                        quantity.format(
                                            Number(product.total_quantity),
                                        )
                                    }}
                                    <span
                                        class="text-[10px] font-medium text-muted-foreground"
                                        >{{ product.unit }}</span
                                    >
                                </p>
                                <p
                                    class="mt-1 text-[10px] text-muted-foreground"
                                >
                                    نقطه سفارش:
                                    {{
                                        quantity.format(
                                            Number(product.reorder_level),
                                        )
                                    }}
                                </p>
                            </td>
                            <td class="px-4 py-4">
                                <p class="text-xs font-bold">
                                    {{
                                        money.format(
                                            Number(product.inventory_value),
                                        )
                                    }}
                                </p>
                                <p
                                    class="mt-1 text-[10px] text-muted-foreground"
                                >
                                    میانگین:
                                    {{
                                        money.format(
                                            Number(product.average_cost),
                                        )
                                    }}
                                </p>
                            </td>
                            <td class="px-4 py-4">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-semibold"
                                    :class="
                                        stockMeta[product.stock_status]
                                            .className
                                    "
                                >
                                    <component
                                        :is="
                                            stockMeta[product.stock_status].icon
                                        "
                                        class="size-3"
                                    />
                                    {{ stockMeta[product.stock_status].label }}
                                </span>
                                <p
                                    class="mt-2 text-[9px]"
                                    :class="
                                        product.is_active
                                            ? 'text-emerald-600'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ product.is_active ? 'فعال' : 'غیرفعال' }}
                                </p>
                            </td>
                            <td class="px-5 py-4 text-left">
                                <Button
                                    as-child
                                    variant="ghost"
                                    size="sm"
                                    class="rounded-lg"
                                >
                                    <Link :href="editProduct(product.id)">
                                        <PencilLine class="size-4" />
                                        ویرایش
                                    </Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="px-6 py-16 text-center">
                <span
                    class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground"
                >
                    <Boxes class="size-6" />
                </span>
                <h3 class="mt-4 text-sm font-bold">کالایی پیدا نشد</h3>
                <p class="mt-1 text-xs text-muted-foreground">
                    فیلترها را تغییر دهید یا اولین کالا را ثبت کنید.
                </p>
                <Button as-child variant="outline" class="mt-5 rounded-xl">
                    <Link :href="createProduct()">ثبت کالا</Link>
                </Button>
            </div>

            <div
                v-if="products.last_page > 1"
                class="flex flex-col gap-3 border-t border-border/70 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="text-[11px] text-muted-foreground">
                    نمایش {{ count.format(products.from ?? 0) }} تا
                    {{ count.format(products.to ?? 0) }} از
                    {{ count.format(products.total) }}
                </p>
                <div class="flex items-center gap-1">
                    <Button
                        variant="outline"
                        size="icon-sm"
                        :disabled="!products.links[0]?.url"
                        aria-label="صفحه قبل"
                        @click="goToPage(products.links[0]?.url ?? null)"
                    >
                        <ChevronRight class="size-4" />
                    </Button>
                    <Button
                        v-for="(link, index) in products.links.slice(1, -1)"
                        :key="`${link.label}-${index}`"
                        :variant="link.active ? 'default' : 'ghost'"
                        size="icon-sm"
                        class="text-xs"
                        :disabled="!link.url"
                        @click="goToPage(link.url)"
                    >
                        {{ paginationLabel(link.label) }}
                    </Button>
                    <Button
                        variant="outline"
                        size="icon-sm"
                        :disabled="!products.links.at(-1)?.url"
                        aria-label="صفحه بعد"
                        @click="goToPage(products.links.at(-1)?.url ?? null)"
                    >
                        <ChevronLeft class="size-4" />
                    </Button>
                </div>
            </div>
        </section>
    </div>
</template>
