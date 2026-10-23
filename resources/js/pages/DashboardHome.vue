<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Banknote,
    CalendarDays,
    CheckCircle2,
    CircleDollarSign,
    Clock3,
    CreditCard,
    Landmark,
    PackageSearch,
    ReceiptText,
    ShoppingBag,
    ShoppingCart,
    Sparkles,
    TrendingDown,
    TrendingUp,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { create as createSale } from '@/routes/sales';
import { index as productsIndex } from '@/routes/products';
import { index as reportsIndex } from '@/routes/reports';

type DashboardMode = 'financial' | 'sales' | 'inventory' | 'limited' | 'setup';
type Metric = {
    key: string;
    label: string;
    value: string;
    hint: string;
    icon: typeof Wallet;
    tone: string;
};
type ActivityKind =
    | 'sales'
    | 'purchase'
    | 'sales_return'
    | 'purchase_return'
    | 'receipt'
    | 'payment'
    | 'check';

const props = defineProps<{
    dashboard: {
        mode: DashboardMode;
        store: { name: string } | null;
        branch: { name: string } | null;
        fiscal_year: { title: string; ends_on: string } | null;
        metrics: Record<string, string | number>;
        trend: Array<{ date: string; sales: string; purchases: string }>;
        monthly_trend: Array<{
            label: string;
            date: string;
            sales: string;
            purchases: string;
        }>;
        low_stock: {
            count: number;
            items: Array<{
                id: number;
                code: string;
                name: string;
                unit: string;
                quantity: string;
                reorder_level: string;
            }>;
        };
        recent_activity: Array<{
            id: number;
            kind: ActivityKind;
            number: string;
            party: string | null;
            date: string;
            amount: string;
            status: string;
        }>;
        setup_required: boolean;
    };
}>();

const user = computed(() => usePage().props.auth.user);
const trendPeriod = ref<'week' | 'month'>('week');
const count = new Intl.NumberFormat('fa-IR');
const money = new Intl.NumberFormat('fa-IR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});
const quantity = new Intl.NumberFormat('fa-IR', {
    maximumFractionDigits: 3,
});
const date = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
});
const shortDate = new Intl.DateTimeFormat('fa-IR', {
    month: 'short',
    day: 'numeric',
});
const modeTitle = computed(() => {
    const titles: Record<DashboardMode, string> = {
        financial: 'تصویر مالی فروشگاه',
        sales: 'عملکرد فروش امروز',
        inventory: 'وضعیت موجودی انبار',
        limited: 'فضای کاری شما',
        setup: 'راه‌اندازی حساب‌یار',
    };

    return titles[props.dashboard.mode];
});
const modeDescription = computed(() => {
    const descriptions: Record<DashboardMode, string> = {
        financial:
            'نقدینگی، مطالبات، بدهی‌ها و روند فروش با داده واقعی اسناد ثبت‌شده.',
        sales: 'فروش امروز، پیش‌نویس‌ها و آخرین فاکتورهای فروش را ببینید.',
        inventory: 'کالاهایی که به نقطه سفارش رسیده‌اند نیازمند تأمین هستند.',
        limited: 'دسترسی شما در حالت مشاهده محدود تنظیم شده است.',
        setup: 'قبل از نمایش گزارش‌ها، فروشگاه، شعبه و سال مالی باید آماده شوند.',
    };

    return descriptions[props.dashboard.mode];
});
const metricCards = computed<Metric[]>(() => {
    const m = props.dashboard.metrics;

    if (props.dashboard.mode === 'financial') {
        return [
            {
                key: 'cash',
                label: 'موجودی صندوق',
                value: money.format(Number(m.cash_balance ?? 0)),
                hint: 'مانده حساب Posted صندوق',
                icon: Banknote,
                tone: 'bg-emerald-500/10 text-emerald-600',
            },
            {
                key: 'bank',
                label: 'موجودی بانک و کارت',
                value: money.format(Number(m.bank_balance ?? 0)),
                hint: 'مانده حساب بانکی دفتر کل',
                icon: Landmark,
                tone: 'bg-sky-500/10 text-sky-600',
            },
            {
                key: 'receivable',
                label: 'مطالبات مشتریان',
                value: money.format(Number(m.receivables ?? 0)),
                hint: 'مانده فاکتورهای باز',
                icon: ReceiptText,
                tone: 'bg-amber-500/10 text-amber-600',
            },
            {
                key: 'payable',
                label: 'بدهی تأمین‌کنندگان',
                value: money.format(Number(m.payables ?? 0)),
                hint: 'مانده فاکتورهای پرداختنی',
                icon: ShoppingBag,
                tone: 'bg-violet-500/10 text-violet-600',
            },
        ];
    }

    if (props.dashboard.mode === 'sales') {
        return [
            {
                key: 'sales_today',
                label: 'فروش خالص امروز',
                value: money.format(Number(m.sales_today ?? 0)),
                hint: 'پس از کسر مرجوعی‌های امروز',
                icon: CircleDollarSign,
                tone: 'bg-primary/10 text-primary',
            },
            {
                key: 'open_sales',
                label: 'فاکتورهای باز',
                value: count.format(Number(m.open_sales ?? 0)),
                hint: 'تأییدشده یا با پرداخت جزئی',
                icon: ReceiptText,
                tone: 'bg-sky-500/10 text-sky-600',
            },
            {
                key: 'draft_sales',
                label: 'پیش‌نویس‌ها',
                value: count.format(Number(m.draft_sales ?? 0)),
                hint: 'در انتظار تأیید',
                icon: Clock3,
                tone: 'bg-amber-500/10 text-amber-600',
            },
        ];
    }

    if (props.dashboard.mode === 'inventory') {
        return [
            {
                key: 'low_stock',
                label: 'کالاهای نیازمند تأمین',
                value: count.format(props.dashboard.low_stock.count),
                hint: 'موجودی برابر یا کمتر از نقطه سفارش',
                icon: AlertTriangle,
                tone: 'bg-red-500/10 text-red-600',
            },
        ];
    }

    return [];
});
const secondaryMetrics = computed(() => {
    if (props.dashboard.mode !== 'financial') {
        return [];
    }

    const m = props.dashboard.metrics;

    return [
        {
            label: 'فروش امروز',
            value: money.format(Number(m.sales_today ?? 0)),
            icon: TrendingUp,
        },
        {
            label: 'خرید امروز',
            value: money.format(Number(m.purchases_today ?? 0)),
            icon: TrendingDown,
        },
        {
            label: 'فاکتورهای باز فروش',
            value: count.format(Number(m.open_sales ?? 0)),
            icon: ShoppingCart,
        },
        {
            label: 'چک‌های سررسیدگذشته',
            value: count.format(Number(m.overdue_checks ?? 0)),
            icon: AlertTriangle,
        },
        {
            label: 'چک‌های تا ۷ روز آینده',
            value: count.format(Number(m.due_soon_checks ?? 0)),
            icon: CalendarDays,
        },
        {
            label: 'سود دوره تا امروز',
            value: money.format(Number(m.net_profit ?? 0)),
            icon: CheckCircle2,
        },
        {
            label: 'مبلغ چک‌های معلق',
            value: money.format(Number(m.pending_checks_amount ?? 0)),
            icon: CreditCard,
        },
    ];
});
const trendPoints = computed(() =>
    trendPeriod.value === 'week'
        ? props.dashboard.trend
        : props.dashboard.monthly_trend,
);
const maxTrend = computed(() =>
    Math.max(
        1,
        ...trendPoints.value.flatMap((point) => [
            Number(point.sales),
            Number(point.purchases),
        ]),
    ),
);
const barHeight = (value: string) =>
    `${Math.max(2, (Number(value) / maxTrend.value) * 100)}%`;
const activityMeta: Record<ActivityKind, { label: string; className: string }> =
    {
        sales: {
            label: 'فروش',
            className: 'bg-primary/10 text-primary',
        },
        purchase: {
            label: 'خرید',
            className: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
        },
        sales_return: {
            label: 'برگشت فروش',
            className: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
        },
        purchase_return: {
            label: 'برگشت خرید',
            className: 'bg-violet-500/10 text-violet-700 dark:text-violet-300',
        },
        receipt: {
            label: 'دریافت',
            className:
                'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        },
        payment: {
            label: 'پرداخت',
            className: 'bg-red-500/10 text-red-700 dark:text-red-300',
        },
        check: {
            label: 'چک',
            className: 'bg-indigo-500/10 text-indigo-700 dark:text-indigo-300',
        },
    };
const primaryAction = computed(() => {
    if (props.dashboard.mode === 'sales') {
        return { label: 'فروش جدید', href: createSale() };
    }

    if (props.dashboard.mode === 'inventory') {
        return { label: 'مدیریت موجودی', href: productsIndex() };
    }

    if (props.dashboard.mode === 'financial') {
        return { label: 'گزارش‌های مالی', href: reportsIndex() };
    }

    return null;
});

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'داشبورد',
                href: '/dashboard',
            },
        ],
    },
});
</script>

<template>
    <Head title="داشبورد" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="relative overflow-hidden rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.08)_100%)] p-6 sm:p-8"
        >
            <div
                class="pointer-events-none absolute -top-24 left-6 size-64 rounded-full bg-primary/10 blur-3xl"
            />
            <div
                class="relative flex flex-col justify-between gap-6 sm:flex-row sm:items-center"
            >
                <div>
                    <div
                        class="mb-3 inline-flex items-center gap-2 rounded-full border border-primary/15 bg-card/70 px-3 py-1 text-[11px] font-semibold text-primary backdrop-blur"
                    >
                        <Sparkles class="size-3.5" />
                        {{ modeTitle }}
                    </div>
                    <h1 class="text-2xl font-black tracking-tight sm:text-3xl">
                        سلام {{ user.name }}، خوش آمدید
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        {{ modeDescription }}
                    </p>
                    <p
                        v-if="dashboard.store"
                        class="mt-3 text-[10px] text-muted-foreground"
                    >
                        {{ dashboard.store.name }} ·
                        {{ dashboard.branch?.name }} · سال مالی
                        {{ dashboard.fiscal_year?.title }}
                    </p>
                </div>
                <Button
                    v-if="primaryAction"
                    as-child
                    variant="outline"
                    class="h-10 rounded-xl bg-card/70"
                >
                    <Link :href="primaryAction.href">
                        {{ primaryAction.label }}
                        <ArrowLeft class="size-4" />
                    </Link>
                </Button>
            </div>
        </section>

        <section
            v-if="dashboard.setup_required"
            class="rounded-2xl border border-amber-500/20 bg-amber-500/10 p-6"
        >
            <div class="flex gap-3">
                <AlertTriangle class="size-5 shrink-0 text-amber-600" />
                <div>
                    <h2 class="text-sm font-bold">راه‌اندازی ناقص است</h2>
                    <p class="mt-2 text-xs leading-7 text-muted-foreground">
                        برای نمایش Dashboard، فروشگاه MAIN، شعبه پیش‌فرض فعال و
                        سال مالی جاری باید وجود داشته باشند.
                    </p>
                </div>
            </div>
        </section>

        <section
            v-if="metricCards.length"
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
        >
            <article
                v-for="metric in metricCards"
                :key="metric.key"
                class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
            >
                <div class="flex items-start justify-between">
                    <p class="text-xs text-muted-foreground">
                        {{ metric.label }}
                    </p>
                    <span
                        class="flex size-9 items-center justify-center rounded-xl"
                        :class="metric.tone"
                    >
                        <component :is="metric.icon" class="size-4" />
                    </span>
                </div>
                <p class="mt-3 text-2xl font-black">
                    {{ metric.value }}
                    <small
                        v-if="
                            !metric.key.includes('open') &&
                            !metric.key.includes('draft') &&
                            !metric.key.includes('due') &&
                            metric.key !== 'low_stock'
                        "
                        class="text-[9px] font-medium text-muted-foreground"
                    >
                        ریال
                    </small>
                </p>
                <p class="mt-2 text-[10px] text-muted-foreground">
                    {{ metric.hint }}
                </p>
            </article>
        </section>

        <section
            v-if="secondaryMetrics.length"
            class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
        >
            <article
                v-for="metric in secondaryMetrics"
                :key="metric.label"
                class="flex items-center justify-between rounded-xl border border-border/70 bg-card px-4 py-3"
            >
                <div class="flex items-center gap-2 text-xs">
                    <component
                        :is="metric.icon"
                        class="size-4 text-muted-foreground"
                    />
                    <span class="text-muted-foreground">{{
                        metric.label
                    }}</span>
                </div>
                <span class="text-sm font-black">{{ metric.value }}</span>
            </article>
        </section>

        <section
            v-if="dashboard.mode === 'financial' || dashboard.mode === 'sales'"
            class="grid gap-5 xl:grid-cols-[1.5fr_1fr]"
        >
            <article class="rounded-2xl border border-border/70 bg-card p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-bold">روند ۷ روز اخیر</h2>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            فروش خالص و در نمای مالی، خرید خالص
                        </p>
                    </div>
                    <div class="flex gap-3 text-[9px] text-muted-foreground">
                        <span class="flex items-center gap-1">
                            <span class="size-2 rounded-full bg-primary" />
                            فروش
                        </span>
                        <span
                            v-if="dashboard.mode === 'financial'"
                            class="flex items-center gap-1"
                        >
                            <span class="size-2 rounded-full bg-sky-400" />
                            خرید
                        </span>
                    </div>
                    <div class="flex rounded-lg bg-muted p-1">
                        <button
                            type="button"
                            class="rounded-md px-2 py-1 text-[9px] font-semibold transition"
                            :class="
                                trendPeriod === 'week'
                                    ? 'bg-background shadow-sm'
                                    : 'text-muted-foreground'
                            "
                            @click="trendPeriod = 'week'"
                        >
                            ۷ روز
                        </button>
                        <button
                            type="button"
                            class="rounded-md px-2 py-1 text-[9px] font-semibold transition"
                            :class="
                                trendPeriod === 'month'
                                    ? 'bg-background shadow-sm'
                                    : 'text-muted-foreground'
                            "
                            @click="trendPeriod = 'month'"
                        >
                            ۶ ماه
                        </button>
                    </div>
                </div>
                <div class="mt-8 flex h-48 items-end gap-2 sm:gap-4">
                    <div
                        v-for="point in trendPoints"
                        :key="point.date"
                        class="flex h-full flex-1 flex-col justify-end"
                    >
                        <div class="flex h-full items-end justify-center gap-1">
                            <span
                                class="w-2.5 rounded-t-md bg-primary/70 sm:w-3.5"
                                :style="{ height: barHeight(point.sales) }"
                                :title="`فروش ${money.format(Number(point.sales))}`"
                            />
                            <span
                                v-if="dashboard.mode === 'financial'"
                                class="w-2.5 rounded-t-md bg-sky-400/70 sm:w-3.5"
                                :style="{ height: barHeight(point.purchases) }"
                                :title="`خرید ${money.format(Number(point.purchases))}`"
                            />
                        </div>
                        <span
                            class="mt-2 text-center text-[8px] text-muted-foreground"
                        >
                            {{
                                'label' in point
                                    ? point.label
                                    : shortDate.format(
                                          new Date(`${point.date}T00:00:00`),
                                      )
                            }}
                        </span>
                    </div>
                </div>
            </article>

            <article class="rounded-2xl border border-border/70 bg-card p-5">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-bold">فعالیت‌های اخیر</h2>
                    <Clock3 class="size-4 text-muted-foreground" />
                </div>
                <div
                    v-if="dashboard.recent_activity.length"
                    class="mt-4 divide-y"
                >
                    <div
                        v-for="activity in dashboard.recent_activity"
                        :key="`${activity.kind}-${activity.id}`"
                        class="flex items-center justify-between gap-3 py-3"
                    >
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span
                                    class="rounded-full px-2 py-0.5 text-[8px] font-bold"
                                    :class="
                                        activityMeta[activity.kind].className
                                    "
                                >
                                    {{ activityMeta[activity.kind].label }}
                                </span>
                                <span
                                    class="truncate font-mono text-[10px] font-bold"
                                >
                                    {{ activity.number }}
                                </span>
                            </div>
                            <p
                                class="mt-1 truncate text-[9px] text-muted-foreground"
                            >
                                {{ activity.party ?? 'بدون طرف‌حساب' }} ·
                                {{
                                    date.format(
                                        new Date(`${activity.date}T00:00:00`),
                                    )
                                }}
                            </p>
                        </div>
                        <span class="shrink-0 text-[10px] font-bold">
                            {{ money.format(Number(activity.amount)) }}
                        </span>
                    </div>
                </div>
                <p
                    v-else
                    class="py-12 text-center text-xs text-muted-foreground"
                >
                    فعالیتی ثبت نشده است.
                </p>
            </article>
        </section>

        <section
            v-if="
                dashboard.mode === 'financial' || dashboard.mode === 'inventory'
            "
            class="rounded-2xl border border-border/70 bg-card p-5"
        >
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-bold">هشدار موجودی</h2>
                    <p class="mt-1 text-[10px] text-muted-foreground">
                        {{ count.format(dashboard.low_stock.count) }} کالا در
                        نقطه سفارش یا کمتر
                    </p>
                </div>
                <Button as-child variant="ghost" size="sm">
                    <Link :href="productsIndex()">مدیریت کالا</Link>
                </Button>
            </div>
            <div
                v-if="dashboard.low_stock.items.length"
                class="mt-4 grid gap-2 md:grid-cols-2"
            >
                <div
                    v-for="item in dashboard.low_stock.items"
                    :key="item.id"
                    class="flex items-center justify-between rounded-xl bg-red-500/[.045] px-4 py-3"
                >
                    <div>
                        <p class="text-xs font-bold">{{ item.name }}</p>
                        <p class="mt-1 text-[9px] text-muted-foreground">
                            {{ item.code }}
                        </p>
                    </div>
                    <div class="text-left text-[10px]">
                        <p class="font-bold text-red-600 dark:text-red-300">
                            {{ quantity.format(Number(item.quantity)) }}
                            {{ item.unit }}
                        </p>
                        <p class="mt-1 text-muted-foreground">
                            حداقل
                            {{ quantity.format(Number(item.reorder_level)) }}
                        </p>
                    </div>
                </div>
            </div>
            <p
                v-else
                class="mt-5 flex items-center gap-2 text-xs text-muted-foreground"
            >
                <CheckCircle2 class="size-4 text-emerald-600" />
                موجودی کالاها بالاتر از نقطه سفارش است.
            </p>
        </section>

        <section
            v-if="dashboard.mode === 'limited'"
            class="rounded-2xl border border-border/70 bg-card p-8 text-center"
        >
            <Wallet class="mx-auto size-10 text-muted-foreground/35" />
            <p class="mt-3 text-sm font-bold">دسترسی تحلیلی محدود</p>
            <p
                class="mx-auto mt-2 max-w-lg text-xs leading-7 text-muted-foreground"
            >
                برای مشاهده داده‌های مالی و موجودی، سطح دسترسی شما باید توسط
                مدیر سیستم تغییر کند.
            </p>
        </section>
    </div>
</template>
