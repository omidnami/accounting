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
    ReceiptText,
    Search,
} from '@lucide/vue';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import {
    create as createReceipt,
    index as receiptsIndex,
    show as showReceipt,
} from '@/routes/receipts';

type PaymentMethod = 'cash' | 'bank' | 'card' | 'check';
type ReceiptStatus = 'pending' | 'confirmed' | 'bounced' | 'cancelled';

type ReceiptRow = {
    id: number;
    receipt_number: string;
    receipt_date: string;
    amount: string;
    payment_method: PaymentMethod;
    status: ReceiptStatus;
    allocations_count: number;
    customer: {
        name: string;
        code: string;
        phone: string | null;
    };
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

const props = defineProps<{
    receipts: {
        data: ReceiptRow[];
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: PaginationLink[];
    };
    filters: {
        search: string;
        payment_method: PaymentMethod | null;
    };
    summary: {
        total: number;
        received: string;
    };
}>();

const filters = reactive({
    search: props.filters.search,
    payment_method: props.filters.payment_method ?? '',
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
const methodMeta = {
    cash: { label: 'نقدی', icon: Banknote, className: 'text-emerald-600' },
    bank: { label: 'بانکی', icon: HandCoins, className: 'text-sky-600' },
    card: { label: 'کارتی', icon: CreditCard, className: 'text-violet-600' },
    check: { label: 'چکی', icon: ReceiptText, className: 'text-amber-600' },
};
const statusMeta = {
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
const paginationLabel = (label: string) =>
    /^\d+$/.test(label) ? count.format(Number(label)) : '…';

const applyFilters = () => {
    router.get(
        receiptsIndex(),
        {
            search: filters.search.trim() || undefined,
            payment_method: filters.payment_method || undefined,
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
    filters.payment_method = '';
    router.get(receiptsIndex(), {}, { preserveState: true, replace: true });
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
                title: 'دریافت‌ها',
                href: receiptsIndex(),
            },
        ],
    },
});
</script>

<template>
    <Head title="دریافت‌های مشتریان" />

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
                        وصول مطالبات، با تخصیص دقیق به فاکتور
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        یک دریافت می‌تواند چند فاکتور را تسویه کند و مانده
                        فاکتورها به‌صورت خودکار به‌روزرسانی شود.
                    </p>
                </div>
                <Button as-child class="h-11 rounded-xl px-5 shadow-md">
                    <Link :href="createReceipt()">
                        <Plus class="size-4" />
                        دریافت جدید
                    </Link>
                </Button>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2">
            <article
                class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <p class="text-xs text-muted-foreground">تعداد دریافت‌ها</p>
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
                    <p class="text-xs text-muted-foreground">
                        جمع دریافت تأییدشده
                    </p>
                    <CircleDollarSign class="size-4 text-emerald-600" />
                </div>
                <p class="mt-3 text-lg font-black sm:text-xl">
                    {{ money.format(Number(summary.received)) }}
                    <small class="text-[10px] font-medium">ریال</small>
                </p>
            </article>
        </section>

        <form
            class="grid gap-3 rounded-2xl border border-border/70 bg-card p-4 shadow-sm sm:grid-cols-[minmax(0,1fr)_220px_auto]"
            @submit.prevent="applyFilters"
        >
            <label class="relative block">
                <span class="sr-only">جست‌وجوی دریافت</span>
                <Search
                    class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                />
                <input
                    v-model="filters.search"
                    type="search"
                    placeholder="شماره دریافت، مشتری یا کد رهگیری..."
                    class="h-10 w-full rounded-xl border border-input bg-background py-2 pr-9 pl-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                />
            </label>
            <label>
                <span class="sr-only">روش پرداخت</span>
                <select
                    v-model="filters.payment_method"
                    class="h-10 w-full rounded-xl border border-input bg-background px-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                >
                    <option value="">همه روش‌ها</option>
                    <option value="cash">نقدی</option>
                    <option value="bank">بانکی</option>
                    <option value="card">کارتی</option>
                    <option value="check">چکی</option>
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
                <h2 class="text-sm font-bold">فهرست دریافت‌ها</h2>
                <p class="mt-1 text-[11px] text-muted-foreground">
                    {{ count.format(receipts.total) }} نتیجه
                </p>
            </div>

            <div v-if="receipts.data.length" class="overflow-x-auto">
                <table class="w-full min-w-[880px] text-right">
                    <thead
                        class="bg-muted/35 text-[11px] text-muted-foreground"
                    >
                        <tr>
                            <th class="px-5 py-3 font-semibold">دریافت</th>
                            <th class="px-4 py-3 font-semibold">مشتری</th>
                            <th class="px-4 py-3 font-semibold">تاریخ</th>
                            <th class="px-4 py-3 font-semibold">روش</th>
                            <th class="px-4 py-3 font-semibold">مبلغ</th>
                            <th class="px-4 py-3 font-semibold">وضعیت</th>
                            <th class="px-5 py-3 font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        <tr
                            v-for="receipt in receipts.data"
                            :key="receipt.id"
                            class="hover:bg-muted/20"
                        >
                            <td class="px-5 py-4">
                                <p
                                    dir="ltr"
                                    class="text-right font-mono text-xs font-bold"
                                >
                                    {{ receipt.receipt_number }}
                                </p>
                                <p
                                    class="mt-1 text-[9px] text-muted-foreground"
                                >
                                    {{ receipt.allocations_count }} فاکتور
                                </p>
                            </td>
                            <td class="px-4 py-4">
                                <p class="text-xs font-semibold">
                                    {{ receipt.customer.name }}
                                </p>
                                <p
                                    class="mt-1 text-[10px] text-muted-foreground"
                                >
                                    {{ receipt.customer.phone ?? 'بدون تلفن' }}
                                </p>
                            </td>
                            <td class="px-4 py-4 text-[11px]">
                                {{
                                    date.format(
                                        new Date(
                                            `${receipt.receipt_date}T00:00:00`,
                                        ),
                                    )
                                }}
                            </td>
                            <td class="px-4 py-4">
                                <span
                                    class="inline-flex items-center gap-1.5 text-[11px] font-semibold"
                                    :class="
                                        methodMeta[receipt.payment_method]
                                            .className
                                    "
                                >
                                    <component
                                        :is="
                                            methodMeta[receipt.payment_method]
                                                .icon
                                        "
                                        class="size-4"
                                    />
                                    {{
                                        methodMeta[receipt.payment_method].label
                                    }}
                                </span>
                            </td>
                            <td class="px-4 py-4 text-xs font-bold">
                                {{ money.format(Number(receipt.amount)) }}
                            </td>
                            <td class="px-4 py-4">
                                <span
                                    class="rounded-full px-2.5 py-1 text-[10px] font-semibold"
                                    :class="
                                        statusMeta[receipt.status].className
                                    "
                                >
                                    {{ statusMeta[receipt.status].label }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-left">
                                <Button
                                    as-child
                                    variant="ghost"
                                    size="sm"
                                    class="rounded-lg"
                                >
                                    <Link :href="showReceipt(receipt.id)">
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
                <HandCoins class="mx-auto size-10 text-muted-foreground/40" />
                <h3 class="mt-4 text-sm font-bold">دریافتی پیدا نشد</h3>
                <Button as-child variant="outline" class="mt-5 rounded-xl">
                    <Link :href="createReceipt()">ثبت دریافت</Link>
                </Button>
            </div>

            <div
                v-if="receipts.last_page > 1"
                class="flex flex-col gap-3 border-t px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="text-[11px] text-muted-foreground">
                    {{ count.format(receipts.from ?? 0) }} تا
                    {{ count.format(receipts.to ?? 0) }} از
                    {{ count.format(receipts.total) }}
                </p>
                <div class="flex gap-1">
                    <Button
                        variant="outline"
                        size="icon-sm"
                        :disabled="!receipts.links[0]?.url"
                        @click="goToPage(receipts.links[0]?.url ?? null)"
                    >
                        <ChevronRight class="size-4" />
                    </Button>
                    <Button
                        v-for="(link, index) in receipts.links.slice(1, -1)"
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
                        :disabled="!receipts.links.at(-1)?.url"
                        @click="goToPage(receipts.links.at(-1)?.url ?? null)"
                    >
                        <ChevronLeft class="size-4" />
                    </Button>
                </div>
            </div>
        </section>
    </div>
</template>
