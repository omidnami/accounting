<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    ArrowDownToLine,
    ArrowLeft,
    ArrowUpFromLine,
    Boxes,
    CalendarRange,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    Download,
    FilterX,
    Printer,
    Warehouse,
} from '@lucide/vue';
import { computed, reactive } from 'vue';
import InputError from '@/components/InputError.vue';
import JalaliDatePicker from '@/components/JalaliDatePicker.vue';
import ProductPicker from '@/components/ProductPicker.vue';
import { Button } from '@/components/ui/button';
import { cardex as cardexIndex } from '@/routes/inventory';
import { exportMethod as exportCardex } from '@/routes/inventory/cardex';

type MovementType =
    | 'opening'
    | 'purchase'
    | 'sale'
    | 'sales_return'
    | 'purchase_return'
    | 'transfer'
    | 'adjustment';

type MovementRow = {
    id: number;
    movement_date: string;
    direction: 'in' | 'out';
    movement_type: MovementType;
    quantity: string;
    quantity_after: string;
    reference: string | null;
    description: string | null;
    unit_cost?: string;
    total_cost?: string;
    average_cost_after?: string;
    value_after?: string;
};

type Report = {
    product: {
        id: number;
        code: string;
        name: string;
        unit: string;
    };
    warehouse: {
        id: number;
        code: string;
        name: string;
    };
    summary: {
        opening_quantity: string;
        closing_quantity: string;
        inbound_quantity: string;
        outbound_quantity: string;
        net_quantity: string;
        movement_count: number;
        opening_value?: string;
        closing_value?: string;
        opening_average_cost?: string;
        closing_average_cost?: string;
        inbound_value?: string;
        outbound_value?: string;
    };
    movements: {
        data: MovementRow[];
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: Array<{
            url: string | null;
            label: string;
            active: boolean;
        }>;
    };
    include_financials: boolean;
};

type Props = {
    report: Report | null;
    filters: {
        from: string;
        to: string;
        product_id: number | null;
        warehouse_id: number | null;
        movement_type: MovementType | null;
        direction: 'in' | 'out' | null;
    };
    warehouses: Array<{
        id: number;
        code: string;
        name: string;
        is_default: boolean;
        is_active: boolean;
    }>;
    can_view_costs: boolean;
};

const props = defineProps<Props>();
const page = usePage();
const errors = computed(() => page.props.errors);
const filters = reactive({
    from: props.filters.from,
    to: props.filters.to,
    product_id: props.filters.product_id?.toString() ?? '',
    warehouse_id: props.filters.warehouse_id?.toString() ?? '',
    movement_type: props.filters.movement_type ?? '',
    direction: props.filters.direction ?? '',
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
const date = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    timeZone: 'UTC',
});
const movementLabels: Record<MovementType, string> = {
    opening: 'موجودی اول دوره',
    purchase: 'خرید',
    sale: 'فروش',
    sales_return: 'برگشت از فروش',
    purchase_return: 'برگشت خرید',
    transfer: 'انتقال',
    adjustment: 'تعدیل',
};
const directionMeta = {
    in: {
        label: 'ورود',
        className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        icon: ArrowDownToLine,
        sign: '+',
    },
    out: {
        label: 'خروج',
        className: 'bg-red-500/10 text-red-700 dark:text-red-300',
        icon: ArrowUpFromLine,
        sign: '−',
    },
} as const;
const paginationLabel = (label: string) =>
    /^\d+$/.test(label) ? count.format(Number(label)) : '…';
const reportTitle = computed(() =>
    props.can_view_costs ? 'کاردکس تعدادی و ریالی' : 'کاردکس تعدادی',
);
const formatDate = (value: string) =>
    date.format(new Date(`${value}T12:00:00Z`));
const exportHref = (format: 'csv' | 'xlsx' | 'pdf' = 'csv') =>
    props.report
        ? exportCardex({
              query: {
                  format,
                  product_id: props.filters.product_id ?? undefined,
                  warehouse_id: props.filters.warehouse_id ?? undefined,
                  from: props.filters.from,
                  to: props.filters.to,
                  movement_type: props.filters.movement_type ?? undefined,
                  direction: props.filters.direction ?? undefined,
              },
          }).url
        : null;
const printLabel = computed(() =>
    props.report && props.report.movements.last_page > 1
        ? 'چاپ صفحه'
        : 'چاپ / PDF',
);
const selectedProduct = computed(() => props.report?.product ?? null);
const selectedWarehouse = computed(() =>
    props.warehouses.find(
        (warehouse) => warehouse.id === props.filters.warehouse_id,
    ),
);
const selectedMovementLabel = computed(() =>
    props.filters.movement_type
        ? movementLabels[props.filters.movement_type]
        : 'همه گردش‌ها',
);
const selectedDirectionLabel = computed(() =>
    props.filters.direction
        ? directionMeta[props.filters.direction].label
        : 'ورود و خروج',
);
const printReport = () => window.print();

const applyFilters = () => {
    router.get(
        cardexIndex(),
        {
            from: filters.from || undefined,
            to: filters.to || undefined,
            product_id: filters.product_id || undefined,
            warehouse_id: filters.warehouse_id || undefined,
            movement_type: filters.movement_type || undefined,
            direction: filters.direction || undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const resetFilters = () => {
    filters.product_id = '';
    filters.warehouse_id = '';
    filters.movement_type = '';
    filters.direction = '';
    router.get(
        cardexIndex(),
        {
            from: filters.from || undefined,
            to: filters.to || undefined,
        },
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
                title: 'کاردکس موجودی',
                href: cardexIndex(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="reportTitle" />

    <div
        class="print-report mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8"
    >
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
                        <ClipboardList class="size-5" />
                        <span class="text-xs font-bold">گردش حساب موجودی</span>
                    </div>
                    <h1 class="text-2xl font-black tracking-tight sm:text-3xl">
                        {{ reportTitle }}
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        مانده هر ردیف از snapshot حرکت انبار محاسبه می‌شود؛
                        بنابراین گزارش تاریخی با تغییرات بعدی موجودی عوض
                        نمی‌شود.
                    </p>
                </div>
                <div
                    v-if="report"
                    class="rounded-2xl border border-primary/15 bg-card/75 px-4 py-3 text-xs shadow-sm backdrop-blur"
                >
                    <p class="font-bold">{{ report.product.name }}</p>
                    <p class="mt-1 text-muted-foreground">
                        {{ report.warehouse.name }} ·
                        <span dir="ltr" class="font-mono">{{
                            report.product.code
                        }}</span>
                    </p>
                </div>
                <div
                    v-if="exportHref()"
                    class="flex flex-wrap gap-2 print:hidden"
                >
                    <Button as-child variant="outline" class="rounded-xl">
                        <a :href="exportHref('csv')!"
                            ><Download class="size-4" /> CSV (Excel)</a
                        >
                    </Button>
                    <Button as-child variant="outline" class="rounded-xl">
                        <a :href="exportHref('xlsx')!"
                            ><Download class="size-4" /> XLSX</a
                        >
                    </Button>
                    <Button as-child variant="outline" class="rounded-xl">
                        <a :href="exportHref('pdf')!"
                            ><Download class="size-4" /> PDF سرور</a
                        >
                    </Button>
                    <Button
                        type="button"
                        class="rounded-xl"
                        @click="printReport"
                    >
                        <Printer class="size-4" /> {{ printLabel }}
                    </Button>
                </div>
            </div>
        </section>

        <form
            class="grid gap-3 rounded-2xl border border-border/70 bg-card p-4 shadow-sm md:grid-cols-2 xl:grid-cols-4 print:hidden"
            @submit.prevent="applyFilters"
        >
            <label class="space-y-1.5">
                <span class="text-xs font-semibold text-muted-foreground"
                    >از تاریخ</span
                >
                <JalaliDatePicker v-model="filters.from" required />
                <InputError :message="errors.from" />
            </label>
            <label class="space-y-1.5">
                <span class="text-xs font-semibold text-muted-foreground"
                    >تا تاریخ</span
                >
                <JalaliDatePicker v-model="filters.to" required />
                <InputError :message="errors.to" />
            </label>
            <label class="space-y-1.5">
                <span class="text-xs font-semibold text-muted-foreground"
                    >کالا</span
                >
                <ProductPicker
                    v-model="filters.product_id"
                    endpoint="/inventory/lookups/products"
                    :warehouse-id="filters.warehouse_id"
                    include-inactive
                    :selected-label="
                        selectedProduct
                            ? `${selectedProduct.name} — ${selectedProduct.code}`
                            : ''
                    "
                    placeholder="جست‌وجوی نام، کد یا بارکد..."
                />
                <InputError :message="errors.product_id" />
            </label>
            <label class="space-y-1.5">
                <span class="text-xs font-semibold text-muted-foreground"
                    >انبار</span
                >
                <span class="relative block">
                    <Warehouse
                        class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                    />
                    <select
                        v-model="filters.warehouse_id"
                        required
                        class="h-11 w-full appearance-none rounded-xl border border-input bg-background py-2 pr-9 pl-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                    >
                        <option value="">انتخاب انبار</option>
                        <option
                            v-for="warehouse in warehouses"
                            :key="warehouse.id"
                            :value="warehouse.id.toString()"
                        >
                            {{ warehouse.name }}
                            {{ warehouse.is_active ? '' : '(غیرفعال)' }}
                        </option>
                    </select>
                </span>
                <InputError :message="errors.warehouse_id" />
            </label>
            <label class="space-y-1.5">
                <span class="text-xs font-semibold text-muted-foreground"
                    >نوع گردش</span
                >
                <select
                    v-model="filters.movement_type"
                    class="h-11 w-full rounded-xl border border-input bg-background px-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                >
                    <option value="">همه گردش‌ها</option>
                    <option
                        v-for="(label, value) in movementLabels"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option>
                </select>
                <InputError :message="errors.movement_type" />
            </label>
            <label class="space-y-1.5">
                <span class="text-xs font-semibold text-muted-foreground"
                    >جهت</span
                >
                <select
                    v-model="filters.direction"
                    class="h-11 w-full rounded-xl border border-input bg-background px-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                >
                    <option value="">ورود و خروج</option>
                    <option value="in">فقط ورود</option>
                    <option value="out">فقط خروج</option>
                </select>
                <InputError :message="errors.direction" />
            </label>
            <div class="flex items-end gap-2 xl:col-span-2">
                <Button type="submit" class="h-11 flex-1 rounded-xl px-5">
                    <CalendarRange class="size-4" />
                    نمایش کاردکس
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    class="h-11 rounded-xl px-4"
                    @click="resetFilters"
                >
                    <FilterX class="size-4" />
                    پاک‌کردن
                </Button>
            </div>
        </form>

        <section
            v-if="report"
            class="hidden rounded-2xl border bg-card p-4 text-xs print:block"
        >
            <div class="grid gap-3 md:grid-cols-3">
                <p>
                    <span class="font-bold">گزارش:</span>
                    {{ reportTitle }}
                </p>
                <p>
                    <span class="font-bold">بازه:</span>
                    {{ formatDate(filters.from) }} تا
                    {{ formatDate(filters.to) }}
                </p>
                <p>
                    <span class="font-bold">کالا:</span>
                    {{ selectedProduct?.name ?? report.product.name }}
                    <span dir="ltr">({{ report.product.code }})</span>
                </p>
                <p>
                    <span class="font-bold">انبار:</span>
                    {{ selectedWarehouse?.name ?? report.warehouse.name }}
                </p>
                <p>
                    <span class="font-bold">نوع گردش:</span>
                    {{ selectedMovementLabel }}
                </p>
                <p>
                    <span class="font-bold">جهت:</span>
                    {{ selectedDirectionLabel }}
                </p>
            </div>
            <p
                v-if="report.movements.last_page > 1"
                class="mt-3 border-t pt-3 font-semibold"
            >
                این چاپ فقط صفحه
                {{ count.format(report.movements.current_page) }} از
                {{ count.format(report.movements.last_page) }} است. برای کل بازه
                از خروجی CSV استفاده کنید.
            </p>
        </section>

        <template v-if="report">
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <p class="text-xs text-muted-foreground">مانده اول دوره</p>
                    <p class="mt-2 text-2xl font-black">
                        {{
                            quantity.format(
                                Number(report.summary.opening_quantity),
                            )
                        }}
                        <span
                            class="text-xs font-medium text-muted-foreground"
                            >{{ report.product.unit }}</span
                        >
                    </p>
                    <p
                        v-if="report.summary.opening_value"
                        class="mt-2 text-[11px] text-muted-foreground"
                    >
                        {{ money.format(Number(report.summary.opening_value)) }}
                        ریال
                    </p>
                </article>
                <article
                    class="rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-5 shadow-sm"
                >
                    <p class="text-xs text-muted-foreground">
                        ورود در محدوده فیلتر
                    </p>
                    <p
                        class="mt-2 text-2xl font-black text-emerald-700 dark:text-emerald-300"
                    >
                        {{
                            quantity.format(
                                Number(report.summary.inbound_quantity),
                            )
                        }}
                        <span class="text-xs font-medium">{{
                            report.product.unit
                        }}</span>
                    </p>
                    <p
                        v-if="report.summary.inbound_value"
                        class="mt-2 text-[11px] text-muted-foreground"
                    >
                        {{ money.format(Number(report.summary.inbound_value)) }}
                        ریال
                    </p>
                </article>
                <article
                    class="rounded-2xl border border-red-500/20 bg-red-500/5 p-5 shadow-sm"
                >
                    <p class="text-xs text-muted-foreground">
                        خروج در محدوده فیلتر
                    </p>
                    <p
                        class="mt-2 text-2xl font-black text-red-700 dark:text-red-300"
                    >
                        {{
                            quantity.format(
                                Number(report.summary.outbound_quantity),
                            )
                        }}
                        <span class="text-xs font-medium">{{
                            report.product.unit
                        }}</span>
                    </p>
                    <p
                        v-if="report.summary.outbound_value"
                        class="mt-2 text-[11px] text-muted-foreground"
                    >
                        {{
                            money.format(Number(report.summary.outbound_value))
                        }}
                        ریال
                    </p>
                </article>
                <article
                    class="rounded-2xl border border-primary/20 bg-primary/5 p-5 shadow-sm"
                >
                    <p class="text-xs text-muted-foreground">
                        مانده پایان دوره
                    </p>
                    <p class="mt-2 text-2xl font-black text-primary">
                        {{
                            quantity.format(
                                Number(report.summary.closing_quantity),
                            )
                        }}
                        <span class="text-xs font-medium">{{
                            report.product.unit
                        }}</span>
                    </p>
                    <p
                        v-if="report.summary.closing_value"
                        class="mt-2 text-[11px] text-muted-foreground"
                    >
                        {{ money.format(Number(report.summary.closing_value)) }}
                        ریال
                    </p>
                </article>
            </section>

            <section
                class="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
            >
                <div
                    class="flex flex-col gap-2 border-b border-border/70 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div>
                        <h2 class="text-sm font-bold">ردیف‌های گردش</h2>
                        <p class="mt-1 text-[11px] text-muted-foreground">
                            {{ count.format(report.summary.movement_count) }}
                            گردش در محدوده فیلتر
                        </p>
                    </div>
                    <p
                        v-if="!can_view_costs"
                        class="rounded-lg bg-muted px-2.5 py-1.5 text-[10px] text-muted-foreground"
                    >
                        ارزش ریالی برای نقش شما نمایش داده نمی‌شود.
                    </p>
                    <p v-else class="text-[10px] text-muted-foreground">
                        جمع ورود و خروج تابع فیلتر است؛ مانده اول و پایان بر
                        اساس کل گردش انبار محاسبه می‌شود.
                    </p>
                </div>

                <div
                    v-if="report.movements.data.length"
                    class="overflow-x-auto"
                >
                    <table
                        class="w-full text-right text-sm"
                        :class="
                            can_view_costs ? 'min-w-[980px]' : 'min-w-[680px]'
                        "
                    >
                        <thead
                            class="border-b bg-muted/40 text-[11px] text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-3">تاریخ</th>
                                <th class="px-4 py-3">نوع و جهت</th>
                                <th class="px-4 py-3">شرح / مرجع</th>
                                <th class="px-4 py-3">مقدار</th>
                                <template v-if="can_view_costs">
                                    <th class="px-4 py-3">بهای واحد</th>
                                    <th class="px-4 py-3">بهای کل</th>
                                    <th class="px-4 py-3">ارزش پس از گردش</th>
                                </template>
                                <th class="px-4 py-3">مانده تعدادی</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/60">
                            <tr
                                v-for="movement in report.movements.data"
                                :key="movement.id"
                                class="transition-colors hover:bg-muted/20"
                            >
                                <td
                                    class="px-4 py-4 text-xs font-semibold whitespace-nowrap"
                                >
                                    {{ formatDate(movement.movement_date) }}
                                </td>
                                <td class="px-4 py-4">
                                    <p class="text-xs font-bold">
                                        {{
                                            movementLabels[
                                                movement.movement_type
                                            ]
                                        }}
                                    </p>
                                    <span
                                        class="mt-1.5 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                        :class="
                                            directionMeta[movement.direction]
                                                .className
                                        "
                                    >
                                        <component
                                            :is="
                                                directionMeta[
                                                    movement.direction
                                                ].icon
                                            "
                                            class="size-3"
                                        />
                                        {{
                                            directionMeta[movement.direction]
                                                .label
                                        }}
                                    </span>
                                </td>
                                <td class="max-w-60 px-4 py-4">
                                    <p
                                        class="truncate text-xs"
                                        :title="
                                            movement.description ?? undefined
                                        "
                                    >
                                        {{ movement.description || '—' }}
                                    </p>
                                    <p
                                        v-if="movement.reference"
                                        dir="ltr"
                                        class="mt-1 truncate text-right font-mono text-[10px] text-muted-foreground"
                                    >
                                        {{ movement.reference }}
                                    </p>
                                </td>
                                <td class="px-4 py-4">
                                    <p
                                        class="text-xs font-black whitespace-nowrap"
                                        :class="
                                            movement.direction === 'in'
                                                ? 'text-emerald-700 dark:text-emerald-300'
                                                : 'text-red-700 dark:text-red-300'
                                        "
                                    >
                                        {{
                                            directionMeta[movement.direction]
                                                .sign
                                        }}
                                        {{
                                            quantity.format(
                                                Number(movement.quantity),
                                            )
                                        }}
                                        {{ report.product.unit }}
                                    </p>
                                </td>
                                <template v-if="can_view_costs">
                                    <td
                                        class="px-4 py-4 text-xs whitespace-nowrap"
                                    >
                                        {{
                                            money.format(
                                                Number(movement.unit_cost ?? 0),
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="px-4 py-4 text-xs font-semibold whitespace-nowrap"
                                    >
                                        {{
                                            money.format(
                                                Number(
                                                    movement.total_cost ?? 0,
                                                ),
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="px-4 py-4 text-xs whitespace-nowrap"
                                    >
                                        {{
                                            money.format(
                                                Number(
                                                    movement.value_after ?? 0,
                                                ),
                                            )
                                        }}
                                    </td>
                                </template>
                                <td class="px-4 py-4">
                                    <p class="text-sm font-black">
                                        {{
                                            quantity.format(
                                                Number(movement.quantity_after),
                                            )
                                        }}
                                        <span
                                            class="text-[10px] font-medium text-muted-foreground"
                                            >{{ report.product.unit }}</span
                                        >
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="px-6 py-16 text-center">
                    <span
                        class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground"
                    >
                        <ClipboardList class="size-6" />
                    </span>
                    <h3 class="mt-4 text-sm font-bold">
                        گردشی در این محدوده پیدا نشد
                    </h3>
                    <p class="mt-1 text-xs text-muted-foreground">
                        تاریخ، نوع گردش یا جهت را تغییر دهید.
                    </p>
                </div>

                <div
                    v-if="report.movements.last_page > 1"
                    class="flex flex-col gap-3 border-t border-border/70 px-5 py-4 sm:flex-row sm:items-center sm:justify-between print:hidden"
                >
                    <p class="text-[11px] text-muted-foreground">
                        نمایش
                        {{ count.format(report.movements.from ?? 0) }} تا
                        {{ count.format(report.movements.to ?? 0) }} از
                        {{ count.format(report.movements.total) }}
                    </p>
                    <div class="flex items-center gap-1">
                        <Button
                            variant="outline"
                            size="icon-sm"
                            :disabled="!report.movements.links[0]?.url"
                            aria-label="صفحه قبل"
                            @click="
                                goToPage(report.movements.links[0]?.url ?? null)
                            "
                        >
                            <ChevronRight class="size-4" />
                        </Button>
                        <Button
                            v-for="(
                                link, index
                            ) in report.movements.links.slice(1, -1)"
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
                            :disabled="!report.movements.links.at(-1)?.url"
                            aria-label="صفحه بعد"
                            @click="
                                goToPage(
                                    report.movements.links.at(-1)?.url ?? null,
                                )
                            "
                        >
                            <ChevronLeft class="size-4" />
                        </Button>
                    </div>
                </div>
            </section>
        </template>

        <section
            v-else
            class="rounded-2xl border border-dashed border-border bg-card px-6 py-20 text-center shadow-sm"
        >
            <span
                class="mx-auto flex size-16 items-center justify-center rounded-3xl bg-primary/10 text-primary"
            >
                <Boxes class="size-7" />
            </span>
            <h2 class="mt-5 text-base font-black">
                کالا و انبار را انتخاب کنید
            </h2>
            <p class="mt-2 text-xs leading-6 text-muted-foreground">
                کاردکس برای یک کالا در یک انبار نمایش داده می‌شود تا مانده و
                ارزش تاریخی قابل اتکا باشد.
            </p>
            <span
                class="mt-4 inline-flex items-center gap-1.5 text-[11px] text-primary"
            >
                <ArrowLeft class="size-3.5" />
                انتخاب را از فیلترهای بالا انجام دهید
            </span>
        </section>
    </div>
</template>
