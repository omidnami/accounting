<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    CircleDollarSign,
    ClipboardList,
    FileClock,
    FileText,
    FilterX,
    Plus,
    Search,
    ShoppingBag,
} from '@lucide/vue';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import {
    create as createPurchase,
    index as purchasesIndex,
    show as showPurchase,
} from '@/routes/purchases';

type Status =
    | 'draft'
    | 'confirmed'
    | 'partially_paid'
    | 'paid'
    | 'reversed'
    | 'cancelled';

const props = defineProps<{
    invoices: {
        data: Array<{
            id: number;
            invoice_number: string;
            invoice_date: string;
            due_date: string | null;
            status: Status;
            total: string;
            supplier: {
                name: string;
                code: string;
                phone: string | null;
            };
        }>;
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: { search: string; status: Status | null };
    summary: {
        total: number;
        draft: number;
        payable: number;
        purchase_total: string;
    };
}>();

const filters = reactive({
    search: props.filters.search,
    status: props.filters.status ?? '',
});
const count = new Intl.NumberFormat('fa-IR');
const money = new Intl.NumberFormat('fa-IR');
const date = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
});
const statuses: Record<Status, { label: string; className: string }> = {
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
        label: 'برگشتی',
        className: 'bg-red-500/10 text-red-700 dark:text-red-300',
    },
    cancelled: {
        label: 'لغوشده',
        className: 'bg-muted text-muted-foreground',
    },
};
const pageLabel = (label: string) =>
    /^\d+$/.test(label) ? count.format(Number(label)) : '…';

const applyFilters = () => {
    router.get(
        purchasesIndex(),
        {
            search: filters.search.trim() || undefined,
            status: filters.status || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
const resetFilters = () => {
    filters.search = '';
    filters.status = '';
    router.get(purchasesIndex(), {}, { preserveState: true, replace: true });
};
const goToPage = (url: string | null) => {
    if (url) {
        router.visit(url, { preserveState: true, preserveScroll: true });
    }
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'خرید و تأمین',
                href: purchasesIndex(),
            },
        ],
    },
});
</script>

<template>
    <Head title="خرید و تأمین" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="relative overflow-hidden rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="relative flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <ShoppingBag class="size-5" />
                        <span class="text-xs font-bold">خرید کالا</span>
                    </div>
                    <h1 class="text-2xl font-black tracking-tight sm:text-3xl">
                        تأمین کالا، از فاکتور تا ورود انبار
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        خرید را پیش‌نویس کنید و هنگام تأیید، موجودی، بدهی
                        تأمین‌کننده و سند حسابداری را یکجا ثبت کنید.
                    </p>
                </div>
                <Button as-child class="h-11 rounded-xl px-5">
                    <Link :href="createPurchase()">
                        <Plus class="size-4" />
                        خرید جدید
                    </Link>
                </Button>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-2xl border bg-card p-4">
                <div class="flex justify-between">
                    <span class="text-xs text-muted-foreground">کل خریدها</span>
                    <FileText class="size-4 text-primary" />
                </div>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.total) }}
                </p>
            </article>
            <article class="rounded-2xl border bg-card p-4">
                <div class="flex justify-between">
                    <span class="text-xs text-muted-foreground"
                        >پیش‌نویس‌ها</span
                    >
                    <FileClock class="size-4 text-amber-600" />
                </div>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.draft) }}
                </p>
            </article>
            <article class="rounded-2xl border bg-card p-4">
                <div class="flex justify-between">
                    <span class="text-xs text-muted-foreground"
                        >حساب‌های پرداختنی</span
                    >
                    <ClipboardList class="size-4 text-sky-600" />
                </div>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.payable) }}
                </p>
            </article>
            <article class="rounded-2xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">جمع خرید ثبت‌شده</p>
                <p class="mt-3 flex items-center gap-1 text-lg font-black">
                    <CircleDollarSign class="size-4 text-emerald-600" />
                    {{ money.format(Number(summary.purchase_total)) }}
                </p>
            </article>
        </section>

        <form
            class="grid gap-3 rounded-2xl border bg-card p-4 sm:grid-cols-[minmax(0,1fr)_220px_auto]"
            @submit.prevent="applyFilters"
        >
            <label class="relative">
                <Search
                    class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                />
                <input
                    v-model="filters.search"
                    type="search"
                    placeholder="شماره فاکتور، نام یا تلفن تأمین‌کننده..."
                    class="h-10 w-full rounded-xl border bg-background pr-9 pl-3 text-sm"
                />
            </label>
            <select
                v-model="filters.status"
                class="h-10 rounded-xl border bg-background px-3 text-sm"
            >
                <option value="">همه وضعیت‌ها</option>
                <option value="draft">پیش‌نویس</option>
                <option value="confirmed">تأییدشده</option>
                <option value="partially_paid">پرداخت جزئی</option>
                <option value="paid">تسویه‌شده</option>
            </select>
            <div class="flex gap-2">
                <Button type="submit" class="h-10 flex-1 rounded-xl">
                    <Search class="size-4" /> فیلتر
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    class="size-10"
                    @click="resetFilters"
                >
                    <FilterX class="size-4" />
                </Button>
            </div>
        </form>

        <section class="overflow-hidden rounded-2xl border bg-card">
            <div class="border-b px-5 py-4">
                <h2 class="text-sm font-bold">فاکتورهای خرید</h2>
            </div>
            <div v-if="invoices.data.length" class="overflow-x-auto">
                <table class="w-full min-w-[850px] text-right">
                    <thead
                        class="bg-muted/35 text-[11px] text-muted-foreground"
                    >
                        <tr>
                            <th class="px-5 py-3">فاکتور</th>
                            <th class="px-4 py-3">تأمین‌کننده</th>
                            <th class="px-4 py-3">تاریخ</th>
                            <th class="px-4 py-3">مبلغ</th>
                            <th class="px-4 py-3">وضعیت</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="invoice in invoices.data" :key="invoice.id">
                            <td class="px-5 py-4 font-mono text-xs font-bold">
                                {{ invoice.invoice_number }}
                            </td>
                            <td class="px-4 py-4">
                                <p class="text-xs font-semibold">
                                    {{ invoice.supplier.name }}
                                </p>
                                <p
                                    class="mt-1 text-[10px] text-muted-foreground"
                                >
                                    {{ invoice.supplier.phone ?? 'بدون تلفن' }}
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
                                    class="rounded-full px-2.5 py-1 text-[10px] font-semibold"
                                    :class="statuses[invoice.status].className"
                                    >{{ statuses[invoice.status].label }}</span
                                >
                            </td>
                            <td class="px-5 py-4 text-left">
                                <Button as-child variant="ghost" size="sm">
                                    <Link :href="showPurchase(invoice.id)">
                                        مشاهده <ChevronLeft class="size-4" />
                                    </Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="px-6 py-16 text-center">
                <ShoppingBag class="mx-auto size-10 text-muted-foreground/40" />
                <p class="mt-3 text-sm font-bold">فاکتور خریدی پیدا نشد</p>
                <Button as-child class="mt-5 rounded-xl">
                    <Link :href="createPurchase()">ثبت خرید</Link>
                </Button>
            </div>
            <div
                v-if="invoices.last_page > 1"
                class="flex items-center justify-between border-t px-5 py-4"
            >
                <span class="text-[11px] text-muted-foreground"
                    >{{ invoices.from ?? 0 }} تا {{ invoices.to ?? 0 }} از
                    {{ invoices.total }}</span
                >
                <div class="flex gap-1">
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
                        {{ pageLabel(link.label) }}
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
