<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    CircleDollarSign,
    FileClock,
    FileText,
    FilterX,
    Plus,
    Search,
    ShoppingCart,
} from '@lucide/vue';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import {
    create as createSale,
    index as salesIndex,
    show as showSale,
} from '@/routes/sales';

type SaleStatus =
    | 'draft'
    | 'confirmed'
    | 'partially_paid'
    | 'paid'
    | 'reversed'
    | 'cancelled';

type SaleRow = {
    id: number;
    invoice_number: string;
    invoice_date: string;
    due_date: string | null;
    status: SaleStatus;
    total: string;
    customer: {
        name: string;
        code: string | null;
        phone: string | null;
    };
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

const props = defineProps<{
    invoices: {
        data: SaleRow[];
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: PaginationLink[];
    };
    filters: {
        search: string;
        status: SaleStatus | null;
    };
    summary: {
        total: number;
        draft: number;
        open: number;
        sales_total: string;
    };
}>();

const filters = reactive({
    search: props.filters.search,
    status: props.filters.status ?? '',
});
const count = new Intl.NumberFormat('fa-IR');
const money = new Intl.NumberFormat('fa-IR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});
const statusMeta: Record<SaleStatus, { label: string; className: string }> = {
    draft: {
        label: 'پیش‌نویس',
        className: 'bg-slate-500/10 text-slate-600 dark:text-slate-300',
    },
    confirmed: {
        label: 'تأییدشده',
        className: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
    },
    partially_paid: {
        label: 'پرداخت جزئی',
        className: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    },
    paid: {
        label: 'تسویه‌شده',
        className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    },
    reversed: {
        label: 'برگشت‌خورده',
        className: 'bg-red-500/10 text-red-700 dark:text-red-300',
    },
    cancelled: {
        label: 'لغوشده',
        className: 'bg-muted text-muted-foreground',
    },
};
const date = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
});
const paginationLabel = (label: string) =>
    /^\d+$/.test(label) ? count.format(Number(label)) : '…';

const applyFilters = () => {
    router.get(
        salesIndex(),
        {
            search: filters.search.trim() || undefined,
            status: filters.status || undefined,
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
    filters.status = '';
    router.get(
        salesIndex(),
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
                title: 'فروش و صندوق',
                href: salesIndex(),
            },
        ],
    },
});
</script>

<template>
    <Head title="فروش و صندوق" />

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
                        از انتخاب کالا تا سند فروش
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        پیش‌نویس بسازید، موجودی و اعتبار مشتری را بررسی کنید و
                        فاکتور را به‌صورت اتمیک ثبت کنید.
                    </p>
                </div>
                <Button as-child class="h-11 rounded-xl px-5 shadow-md">
                    <Link :href="createSale()">
                        <Plus class="size-4" />
                        فروش جدید
                    </Link>
                </Button>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article
                class="rounded-2xl border border-border/70 bg-card p-4 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <p class="text-xs text-muted-foreground">کل فاکتورها</p>
                    <FileText class="size-4 text-primary" />
                </div>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.total) }}
                </p>
            </article>
            <article
                class="rounded-2xl border border-border/70 bg-card p-4 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <p class="text-xs text-muted-foreground">پیش‌نویس‌ها</p>
                    <FileClock class="size-4 text-amber-600" />
                </div>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.draft) }}
                </p>
            </article>
            <article
                class="rounded-2xl border border-border/70 bg-card p-4 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <p class="text-xs text-muted-foreground">فاکتورهای باز</p>
                    <CircleDollarSign class="size-4 text-sky-600" />
                </div>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.open) }}
                </p>
            </article>
            <article
                class="rounded-2xl border border-border/70 bg-card p-4 shadow-sm"
            >
                <p class="text-xs text-muted-foreground">جمع فروش ثبت‌شده</p>
                <p class="mt-3 text-lg font-black sm:text-xl">
                    {{ money.format(Number(summary.sales_total)) }}
                    <span class="text-[10px] font-medium text-muted-foreground"
                        >ریال</span
                    >
                </p>
            </article>
        </section>

        <form
            class="grid gap-3 rounded-2xl border border-border/70 bg-card p-4 shadow-sm sm:grid-cols-[minmax(0,1fr)_220px_auto]"
            @submit.prevent="applyFilters"
        >
            <label class="relative block">
                <span class="sr-only">جست‌وجوی فاکتور</span>
                <Search
                    class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                />
                <input
                    v-model="filters.search"
                    type="search"
                    placeholder="شماره فاکتور، نام، کد یا تلفن مشتری..."
                    class="h-10 w-full rounded-xl border border-input bg-background py-2 pr-9 pl-3 text-sm transition outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                />
            </label>
            <label>
                <span class="sr-only">وضعیت فاکتور</span>
                <select
                    v-model="filters.status"
                    class="h-10 w-full rounded-xl border border-input bg-background px-3 text-sm transition outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                >
                    <option value="">همه وضعیت‌ها</option>
                    <option value="draft">پیش‌نویس</option>
                    <option value="confirmed">تأییدشده</option>
                    <option value="partially_paid">پرداخت جزئی</option>
                    <option value="paid">تسویه‌شده</option>
                    <option value="reversed">برگشت‌خورده</option>
                    <option value="cancelled">لغوشده</option>
                </select>
            </label>
            <div class="flex gap-2">
                <Button type="submit" class="h-10 flex-1 rounded-xl px-5">
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
                class="flex items-center justify-between border-b border-border/70 px-5 py-4"
            >
                <div>
                    <h2 class="text-sm font-bold">فاکتورهای فروش</h2>
                    <p class="mt-1 text-[11px] text-muted-foreground">
                        {{ count.format(invoices.total) }} نتیجه
                    </p>
                </div>
            </div>

            <div v-if="invoices.data.length" class="overflow-x-auto">
                <table class="w-full min-w-[850px] text-right">
                    <thead
                        class="bg-muted/35 text-[11px] text-muted-foreground"
                    >
                        <tr>
                            <th class="px-5 py-3 font-semibold">فاکتور</th>
                            <th class="px-4 py-3 font-semibold">مشتری</th>
                            <th class="px-4 py-3 font-semibold">تاریخ</th>
                            <th class="px-4 py-3 font-semibold">مبلغ</th>
                            <th class="px-4 py-3 font-semibold">وضعیت</th>
                            <th class="px-5 py-3 text-left font-semibold">
                                عملیات
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        <tr
                            v-for="invoice in invoices.data"
                            :key="invoice.id"
                            class="transition-colors hover:bg-muted/20"
                        >
                            <td class="px-5 py-4">
                                <p
                                    dir="ltr"
                                    class="text-right font-mono text-xs font-bold"
                                >
                                    {{ invoice.invoice_number }}
                                </p>
                            </td>
                            <td class="px-4 py-4">
                                <p class="text-xs font-semibold">
                                    {{ invoice.customer.name }}
                                </p>
                                <p
                                    class="mt-1 text-[10px] text-muted-foreground"
                                >
                                    {{ invoice.customer.phone ?? 'بدون تلفن' }}
                                </p>
                            </td>
                            <td class="px-4 py-4 text-[11px]">
                                {{
                                    date.format(
                                        new Date(
                                            `${invoice.invoice_date}T00:00:00`,
                                        ),
                                    )
                                }}
                            </td>
                            <td class="px-4 py-4 text-xs font-bold">
                                {{ money.format(Number(invoice.total)) }}
                            </td>
                            <td class="px-4 py-4">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-semibold"
                                    :class="
                                        statusMeta[invoice.status].className
                                    "
                                >
                                    {{ statusMeta[invoice.status].label }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-left">
                                <Button
                                    as-child
                                    variant="ghost"
                                    size="sm"
                                    class="rounded-lg"
                                >
                                    <Link :href="showSale(invoice.id)">
                                        مشاهده
                                        <ChevronLeft class="size-4" />
                                    </Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="px-6 py-16 text-center">
                <ShoppingCart
                    class="mx-auto size-10 text-muted-foreground/40"
                />
                <h3 class="mt-4 text-sm font-bold">فاکتوری پیدا نشد</h3>
                <p class="mt-1 text-xs text-muted-foreground">
                    فیلترها را تغییر دهید یا اولین فروش را ثبت کنید.
                </p>
                <Button as-child variant="outline" class="mt-5 rounded-xl">
                    <Link :href="createSale()">فروش جدید</Link>
                </Button>
            </div>

            <div
                v-if="invoices.last_page > 1"
                class="flex flex-col gap-3 border-t border-border/70 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="text-[11px] text-muted-foreground">
                    نمایش {{ count.format(invoices.from ?? 0) }} تا
                    {{ count.format(invoices.to ?? 0) }} از
                    {{ count.format(invoices.total) }}
                </p>
                <div class="flex items-center gap-1">
                    <Button
                        variant="outline"
                        size="icon-sm"
                        :disabled="!invoices.links[0]?.url"
                        @click="goToPage(invoices.links[0]?.url ?? null)"
                    >
                        <ChevronRight class="size-4" />
                    </Button>
                    <Button
                        v-for="(link, index) in invoices.links.slice(1, -1)"
                        :key="`${link.label}-${index}`"
                        :variant="link.active ? 'default' : 'ghost'"
                        size="icon-sm"
                        :disabled="!link.url"
                        @click="goToPage(link.url)"
                    >
                        {{ paginationLabel(link.label) }}
                    </Button>
                    <Button
                        variant="outline"
                        size="icon-sm"
                        :disabled="!invoices.links.at(-1)?.url"
                        @click="goToPage(invoices.links.at(-1)?.url ?? null)"
                    >
                        <ChevronLeft class="size-4" />
                    </Button>
                </div>
            </div>
        </section>
    </div>
</template>
