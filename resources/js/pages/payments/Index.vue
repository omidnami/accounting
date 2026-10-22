<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Banknote,
    ChevronLeft,
    ChevronRight,
    CircleDollarSign,
    CreditCard,
    FilterX,
    HandCoins,
    Plus,
    Search,
} from '@lucide/vue';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import {
    create as createPayment,
    index as paymentsIndex,
    show as showPayment,
} from '@/routes/payments';

type Method = 'cash' | 'bank' | 'card' | 'check';
type Status = 'pending' | 'confirmed' | 'bounced' | 'cancelled';

const props = defineProps<{
    payments: {
        data: Array<{
            id: number;
            payment_number: string;
            payment_date: string;
            amount: string;
            payment_method: Method;
            status: Status;
            allocations_count: number;
            supplier: { name: string; code: string; phone: string | null };
        }>;
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: { search: string; payment_method: Method | null };
    summary: { total: number; paid: string };
}>();

const filters = reactive({
    search: props.filters.search,
    payment_method: props.filters.payment_method ?? '',
});
const count = new Intl.NumberFormat('fa-IR');
const money = new Intl.NumberFormat('fa-IR');
const date = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
});
const methodLabel: Record<Method, string> = {
    cash: 'نقدی',
    bank: 'بانکی',
    card: 'کارتی',
    check: 'چکی',
};
const statusMeta: Record<Status, { label: string; className: string }> = {
    pending: {
        label: 'در انتظار',
        className: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    },
    confirmed: {
        label: 'تأییدشده',
        className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    },
    bounced: {
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
        paymentsIndex(),
        {
            search: filters.search.trim() || undefined,
            payment_method: filters.payment_method || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
const resetFilters = () => {
    filters.search = '';
    filters.payment_method = '';
    router.get(paymentsIndex(), {}, { preserveState: true, replace: true });
};
const goToPage = (url: string | null) => {
    if (url) {
        router.visit(url, { preserveState: true, preserveScroll: true });
    }
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'پرداخت‌ها', href: paymentsIndex() }],
    },
});
</script>

<template>
    <Head title="پرداخت‌های تأمین‌کنندگان" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="flex flex-col justify-between gap-4 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <CircleDollarSign class="size-5" />
                        <span class="text-xs font-bold"
                            >پرداخت تأمین‌کننده</span
                        >
                    </div>
                    <h1 class="text-2xl font-black sm:text-3xl">
                        تسویه حساب‌های پرداختنی
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        یک پرداخت می‌تواند چند فاکتور خرید را تسویه کند.
                    </p>
                </div>
                <Button as-child class="h-11 rounded-xl">
                    <Link :href="createPayment()">
                        <Plus class="size-4" /> پرداخت جدید
                    </Link>
                </Button>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2">
            <article class="rounded-2xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">تعداد پرداخت‌ها</p>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.total) }}
                </p>
            </article>
            <article class="rounded-2xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">جمع پرداخت تأییدشده</p>
                <p class="mt-3 text-xl font-black">
                    {{ money.format(Number(summary.paid)) }}
                    <small class="text-[9px]">ریال</small>
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
                    placeholder="شماره پرداخت، تأمین‌کننده یا مرجع..."
                    class="h-10 w-full rounded-xl border bg-background pr-9 pl-3 text-sm"
                />
            </label>
            <select
                v-model="filters.payment_method"
                class="h-10 rounded-xl border bg-background px-3 text-sm"
            >
                <option value="">همه روش‌ها</option>
                <option value="cash">نقدی</option>
                <option value="bank">بانکی</option>
                <option value="card">کارتی</option>
                <option value="check">چکی</option>
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
                <h2 class="text-sm font-bold">فهرست پرداخت‌ها</h2>
            </div>
            <div v-if="payments.data.length" class="overflow-x-auto">
                <table class="w-full min-w-[880px] text-right">
                    <thead class="bg-muted/35 text-[11px]">
                        <tr>
                            <th class="px-5 py-3">پرداخت</th>
                            <th class="px-4 py-3">تأمین‌کننده</th>
                            <th class="px-4 py-3">تاریخ</th>
                            <th class="px-4 py-3">روش</th>
                            <th class="px-4 py-3">مبلغ</th>
                            <th class="px-4 py-3">وضعیت</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="payment in payments.data" :key="payment.id">
                            <td class="px-5 py-4">
                                <p class="font-mono text-xs font-bold">
                                    {{ payment.payment_number }}
                                </p>
                                <p
                                    class="mt-1 text-[9px] text-muted-foreground"
                                >
                                    {{ payment.allocations_count }} فاکتور
                                </p>
                            </td>
                            <td class="px-4 py-4">
                                <p class="text-xs font-semibold">
                                    {{ payment.supplier.name }}
                                </p>
                                <p
                                    class="mt-1 text-[10px] text-muted-foreground"
                                >
                                    {{ payment.supplier.phone ?? 'بدون تلفن' }}
                                </p>
                            </td>
                            <td class="px-4 py-4 text-[11px]">
                                {{
                                    date.format(
                                        new Date(
                                            `${payment.payment_date}T00:00:00`,
                                        ),
                                    )
                                }}
                            </td>
                            <td class="px-4 py-4 text-xs">
                                {{ methodLabel[payment.payment_method] }}
                            </td>
                            <td class="px-4 py-4 text-xs font-bold">
                                {{ money.format(Number(payment.amount)) }}
                            </td>
                            <td class="px-4 py-4">
                                <span
                                    class="rounded-full px-2.5 py-1 text-[10px] font-semibold"
                                    :class="
                                        statusMeta[payment.status].className
                                    "
                                >
                                    {{ statusMeta[payment.status].label }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-left">
                                <Button as-child variant="ghost" size="sm">
                                    <Link :href="showPayment(payment.id)">
                                        مشاهده <ChevronLeft class="size-4" />
                                    </Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="px-6 py-16 text-center">
                <HandCoins class="mx-auto size-10 text-muted-foreground/40" />
                <p class="mt-3 text-sm font-bold">پرداختی پیدا نشد</p>
                <Button as-child class="mt-5 rounded-xl">
                    <Link :href="createPayment()">ثبت پرداخت</Link>
                </Button>
            </div>
            <div
                v-if="payments.last_page > 1"
                class="flex items-center justify-between border-t px-5 py-4"
            >
                <span class="text-[11px] text-muted-foreground"
                    >{{ payments.from ?? 0 }} تا {{ payments.to ?? 0 }} از
                    {{ payments.total }}</span
                >
                <div class="flex gap-1">
                    <Button
                        variant="outline"
                        size="icon-sm"
                        :disabled="!payments.links[0]?.url"
                        @click="goToPage(payments.links[0]?.url ?? null)"
                    >
                        <ChevronRight class="size-4" />
                    </Button>
                    <Button
                        v-for="(link, index) in payments.links.slice(1, -1)"
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
                        :disabled="!payments.links.at(-1)?.url"
                        @click="goToPage(payments.links.at(-1)?.url ?? null)"
                    >
                        <ChevronLeft class="size-4" />
                    </Button>
                </div>
            </div>
        </section>
    </div>
</template>
