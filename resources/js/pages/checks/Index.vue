<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarClock,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    CircleDollarSign,
    FilterX,
    Plus,
    ReceiptText,
    Search,
    WalletCards,
} from '@lucide/vue';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import {
    create as createCheck,
    index as checksIndex,
    show as showCheck,
} from '@/routes/checks';
import { show as showPayment } from '@/routes/payments';
import { show as showReceipt } from '@/routes/receipts';

type CheckType = 'receivable' | 'payable';
type CheckStatus = 'pending' | 'collected' | 'spent' | 'bounced';

type TreasuryDocument = {
    kind: 'receipt' | 'payment';
    id: number;
    number: string;
    status: string;
};

type CheckRow = {
    id: number;
    document_number: string | null;
    check_number: string;
    bank_name: string;
    amount: string;
    issue_date: string | null;
    due_date: string;
    type: CheckType;
    status: CheckStatus;
    party: {
        kind: 'customer' | 'supplier';
        id: number | null;
        code: string | null;
        name: string | null;
    };
    treasury_document: TreasuryDocument | null;
};

const props = defineProps<{
    checks: {
        data: CheckRow[];
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: {
        search: string;
        type: CheckType | null;
        status: CheckStatus | null;
        due_state: 'overdue' | 'due_soon' | null;
    };
    summary: {
        total: number;
        pending: number;
        pending_amount: string;
        overdue: number;
    };
}>();

const filters = reactive({
    search: props.filters.search,
    type: props.filters.type ?? '',
    status: props.filters.status ?? '',
    due_state: props.filters.due_state ?? '',
});
const count = new Intl.NumberFormat('fa-IR');
const money = new Intl.NumberFormat('fa-IR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});
const date = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
});
const typeMeta: Record<CheckType, { label: string; className: string }> = {
    receivable: {
        label: 'دریافتی',
        className: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
    },
    payable: {
        label: 'پرداختی',
        className: 'bg-violet-500/10 text-violet-700 dark:text-violet-300',
    },
};
const statusMeta: Record<CheckStatus, { label: string; className: string }> = {
    pending: {
        label: 'در انتظار',
        className: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    },
    collected: {
        label: 'وصول‌شده',
        className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    },
    spent: {
        label: 'خرج‌شده',
        className: 'bg-primary/10 text-primary',
    },
    bounced: {
        label: 'برگشتی',
        className: 'bg-red-500/10 text-red-700 dark:text-red-300',
    },
};

const today = new Date();
today.setHours(0, 0, 0, 0);

const dueMeta = (check: CheckRow) => {
    if (check.status !== 'pending') {
        return null;
    }

    const due = new Date(`${check.due_date}T00:00:00`);
    const days = Math.ceil(
        (due.getTime() - today.getTime()) / (1000 * 60 * 60 * 24),
    );

    if (days < 0) {
        return {
            label: `${count.format(Math.abs(days))} روز گذشته`,
            className: 'text-red-600 dark:text-red-300',
        };
    }

    if (days === 0) {
        return {
            label: 'سررسید امروز',
            className: 'text-amber-600 dark:text-amber-300',
        };
    }

    if (days <= 7) {
        return {
            label: `${count.format(days)} روز مانده`,
            className: 'text-amber-600 dark:text-amber-300',
        };
    }

    return null;
};
const pageLabel = (label: string) =>
    /^\d+$/.test(label) ? count.format(Number(label)) : '…';
const applyFilters = () => {
    router.get(
        checksIndex(),
        {
            search: filters.search.trim() || undefined,
            type: filters.type || undefined,
            status: filters.status || undefined,
            due_state: filters.due_state || undefined,
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
    filters.type = '';
    filters.status = '';
    filters.due_state = '';
    router.get(checksIndex(), {}, { preserveState: true, replace: true });
};
const goToPage = (url: string | null) => {
    if (url) {
        router.visit(url, { preserveState: true, preserveScroll: true });
    }
};
const treasuryDocumentUrl = (document: TreasuryDocument) =>
    document.kind === 'receipt'
        ? showReceipt(document.id)
        : showPayment(document.id);

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'چک‌ها', href: checksIndex() }],
    },
});
</script>

<template>
    <Head title="مدیریت چک‌ها" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="relative overflow-hidden rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="relative flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <WalletCards class="size-5" />
                        <span class="text-xs font-bold">خزانه و چک</span>
                    </div>
                    <h1 class="text-2xl font-black tracking-tight sm:text-3xl">
                        چرخه چک‌های دریافتنی و پرداختنی
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        چک‌ها را ثبت، به فاکتورها متصل و وصول، خرج یا برگشت
                        کنید. هر تغییر وضعیت سند حسابداری مستقل دارد.
                    </p>
                </div>
                <Button as-child class="h-11 rounded-xl px-5 shadow-md">
                    <Link :href="createCheck()">
                        <Plus class="size-4" />
                        ثبت چک
                    </Link>
                </Button>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article
                class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <p class="text-xs text-muted-foreground">کل چک‌ها</p>
                    <ReceiptText class="size-4 text-primary" />
                </div>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.total) }}
                </p>
            </article>
            <article
                class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <p class="text-xs text-muted-foreground">در انتظار</p>
                    <CalendarClock class="size-4 text-amber-600" />
                </div>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.pending) }}
                </p>
            </article>
            <article
                class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <p class="text-xs text-muted-foreground">
                        مبلغ چک‌های در انتظار
                    </p>
                    <CircleDollarSign class="size-4 text-emerald-600" />
                </div>
                <p class="mt-3 text-lg font-black sm:text-xl">
                    {{ money.format(Number(summary.pending_amount)) }}
                    <small class="text-[10px] font-medium">ریال</small>
                </p>
            </article>
            <article
                class="rounded-2xl border border-red-500/15 bg-red-500/[.055] p-5 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <p class="text-xs text-red-700 dark:text-red-300">
                        سررسید گذشته
                    </p>
                    <AlertTriangle class="size-4 text-red-600" />
                </div>
                <p
                    class="mt-3 text-2xl font-black text-red-700 dark:text-red-300"
                >
                    {{ count.format(summary.overdue) }}
                </p>
            </article>
        </section>

        <form
            class="grid gap-3 rounded-2xl border border-border/70 bg-card p-4 shadow-sm lg:grid-cols-[minmax(0,1fr)_160px_160px_170px_auto]"
            @submit.prevent="applyFilters"
        >
            <label class="relative block">
                <span class="sr-only">جست‌وجوی چک</span>
                <Search
                    class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                />
                <input
                    v-model="filters.search"
                    type="search"
                    placeholder="شماره چک، بانک یا طرف‌حساب..."
                    class="h-10 w-full rounded-xl border border-input bg-background py-2 pr-9 pl-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                />
            </label>
            <select
                v-model="filters.type"
                class="h-10 rounded-xl border border-input bg-background px-3 text-sm outline-none"
            >
                <option value="">همه انواع</option>
                <option value="receivable">دریافتی</option>
                <option value="payable">پرداختی</option>
            </select>
            <select
                v-model="filters.status"
                class="h-10 rounded-xl border border-input bg-background px-3 text-sm outline-none"
            >
                <option value="">همه وضعیت‌ها</option>
                <option value="pending">در انتظار</option>
                <option value="collected">وصول‌شده</option>
                <option value="spent">خرج‌شده</option>
                <option value="bounced">برگشتی</option>
            </select>
            <select
                v-model="filters.due_state"
                class="h-10 rounded-xl border border-input bg-background px-3 text-sm outline-none"
            >
                <option value="">همه سررسیدها</option>
                <option value="overdue">سررسید گذشته</option>
                <option value="due_soon">تا ۷ روز آینده</option>
            </select>
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
                    @click="resetFilters"
                >
                    <FilterX class="size-4" />
                </Button>
            </div>
        </form>

        <section
            class="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
        >
            <div class="border-b border-border/70 px-5 py-4">
                <h2 class="text-sm font-bold">فهرست چک‌ها</h2>
                <p class="mt-1 text-[11px] text-muted-foreground">
                    {{ count.format(checks.total) }} نتیجه
                </p>
            </div>

            <div v-if="checks.data.length" class="overflow-x-auto">
                <table class="w-full min-w-[980px] text-right">
                    <thead
                        class="bg-muted/35 text-[11px] text-muted-foreground"
                    >
                        <tr>
                            <th class="px-5 py-3 font-semibold">چک</th>
                            <th class="px-4 py-3 font-semibold">طرف‌حساب</th>
                            <th class="px-4 py-3 font-semibold">نوع</th>
                            <th class="px-4 py-3 font-semibold">سررسید</th>
                            <th class="px-4 py-3 font-semibold">مبلغ</th>
                            <th class="px-4 py-3 font-semibold">وضعیت</th>
                            <th class="px-4 py-3 font-semibold">سند مرتبط</th>
                            <th class="px-5 py-3 font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        <tr
                            v-for="check in checks.data"
                            :key="check.id"
                            class="transition-colors hover:bg-muted/25"
                        >
                            <td class="px-5 py-4">
                                <p class="font-mono text-xs font-black">
                                    {{
                                        check.document_number ??
                                        check.check_number
                                    }}
                                </p>
                                <p
                                    class="mt-1 text-[10px] text-muted-foreground"
                                >
                                    {{ check.bank_name }} ·
                                    {{ check.check_number }}
                                </p>
                            </td>
                            <td class="px-4 py-4">
                                <p class="text-xs font-semibold">
                                    {{ check.party.name ?? '—' }}
                                </p>
                                <p
                                    class="mt-1 text-[10px] text-muted-foreground"
                                >
                                    {{ check.party.code ?? 'بدون کد' }}
                                </p>
                            </td>
                            <td class="px-4 py-4">
                                <span
                                    class="rounded-full px-2.5 py-1 text-[10px] font-semibold"
                                    :class="typeMeta[check.type].className"
                                >
                                    {{ typeMeta[check.type].label }}
                                </span>
                            </td>
                            <td class="px-4 py-4 text-[11px]">
                                {{
                                    date.format(
                                        new Date(`${check.due_date}T00:00:00`),
                                    )
                                }}
                                <p
                                    v-if="dueMeta(check)"
                                    class="mt-1 text-[9px] font-semibold"
                                    :class="dueMeta(check)?.className"
                                >
                                    {{ dueMeta(check)?.label }}
                                </p>
                            </td>
                            <td class="px-4 py-4 text-xs font-bold">
                                {{ money.format(Number(check.amount)) }}
                            </td>
                            <td class="px-4 py-4">
                                <span
                                    class="rounded-full px-2.5 py-1 text-[10px] font-semibold"
                                    :class="statusMeta[check.status].className"
                                >
                                    {{ statusMeta[check.status].label }}
                                </span>
                            </td>
                            <td class="px-4 py-4">
                                <Link
                                    v-if="check.treasury_document"
                                    :href="
                                        treasuryDocumentUrl(
                                            check.treasury_document,
                                        )
                                    "
                                    class="font-mono text-[10px] font-semibold text-primary hover:underline"
                                >
                                    {{ check.treasury_document.number }}
                                </Link>
                                <span
                                    v-else
                                    class="text-[10px] text-muted-foreground"
                                >
                                    هنوز متصل نیست
                                </span>
                            </td>
                            <td class="px-5 py-4 text-left">
                                <Button as-child variant="ghost" size="sm">
                                    <Link :href="showCheck(check.id)">
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
                <CheckCircle2
                    class="mx-auto size-10 text-muted-foreground/35"
                />
                <p class="mt-3 text-sm font-bold">چکی پیدا نشد</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    فیلترها را تغییر دهید یا اولین چک را ثبت کنید.
                </p>
                <Button as-child class="mt-5 rounded-xl">
                    <Link :href="createCheck()">ثبت چک جدید</Link>
                </Button>
            </div>
            <div
                v-if="checks.last_page > 1"
                class="flex items-center justify-between border-t border-border/70 px-5 py-4"
            >
                <span class="text-[11px] text-muted-foreground">
                    {{ checks.from ?? 0 }} تا {{ checks.to ?? 0 }} از
                    {{ checks.total }}
                </span>
                <div class="flex gap-1">
                    <Button
                        variant="outline"
                        size="icon-sm"
                        :disabled="!checks.links[0]?.url"
                        @click="goToPage(checks.links[0]?.url ?? null)"
                    >
                        <ChevronRight class="size-4" />
                    </Button>
                    <Button
                        v-for="(link, index) in checks.links.slice(1, -1)"
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
                        :disabled="!checks.links.at(-1)?.url"
                        @click="goToPage(checks.links.at(-1)?.url ?? null)"
                    >
                        <ChevronLeft class="size-4" />
                    </Button>
                </div>
            </div>
        </section>
    </div>
</template>
