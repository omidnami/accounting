<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Banknote,
    CalendarDays,
    Check,
    CircleAlert,
    CircleX,
    Clock3,
    CreditCard,
    HandCoins,
    Landmark,
    Printer,
    ReceiptText,
    UserRound,
} from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    create as createReceipt,
    index as receiptsIndex,
} from '@/routes/receipts';
import { show as showSale } from '@/routes/sales';

type PaymentMethod = 'cash' | 'bank' | 'card' | 'check';
type ReceiptStatus = 'pending' | 'confirmed' | 'bounced' | 'cancelled';

const props = defineProps<{
    receipt: {
        id: number;
        receipt_number: string;
        receipt_date: string;
        amount: string;
        payment_method: PaymentMethod;
        status: ReceiptStatus;
        reference: string | null;
        description: string | null;
        treasury_account: string | null;
        customer: {
            id: number;
            name: string;
            code: string;
            phone: string | null;
        };
        allocations: Array<{
            id: number;
            amount: string;
            invoice: {
                id: number;
                invoice_number: string;
                invoice_date: string;
                status: string;
                total: string;
            };
        }>;
    };
}>();

const money = new Intl.NumberFormat('fa-IR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});
const date = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
});
const methodMeta = {
    cash: { label: 'نقدی', icon: Banknote },
    bank: { label: 'بانکی', icon: Landmark },
    card: { label: 'کارتی', icon: CreditCard },
    check: { label: 'چکی', icon: ReceiptText },
};
const statusMeta = {
    pending: {
        label: 'در انتظار',
        icon: Clock3,
        badgeClass: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
        panelClass:
            'border-amber-500/20 bg-amber-500/[.055] text-amber-700 dark:text-amber-300',
        message: 'این دریافت هنوز تأیید و سند نهایی نشده است.',
    },
    confirmed: {
        label: 'تأییدشده',
        icon: Check,
        badgeClass: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        panelClass:
            'border-emerald-500/20 bg-emerald-500/[.055] text-emerald-700 dark:text-emerald-300',
        message:
            'این دریافت تأیید شده، به فاکتورهای بالا تخصیص یافته و سند حسابداری آن ثبت شده است. برای اصلاح باید فرآیند برگشت کنترل‌شده انجام شود.',
    },
    bounced: {
        label: 'برگشتی',
        icon: CircleAlert,
        badgeClass: 'bg-red-500/10 text-red-700 dark:text-red-300',
        panelClass:
            'border-red-500/20 bg-red-500/[.055] text-red-700 dark:text-red-300',
        message:
            'این دریافت برگشت خورده است و اثر آن بر مطالبات و حساب خزانه باید با سند اصلاحی کنترل شود.',
    },
    cancelled: {
        label: 'لغوشده',
        icon: CircleX,
        badgeClass: 'bg-muted text-muted-foreground',
        panelClass: 'border-border bg-muted/35 text-muted-foreground',
        message: 'این دریافت لغو شده و اثر مالی فعالی ندارد.',
    },
};
const receiptStatus = computed(() => statusMeta[props.receipt.status]);
const printReceipt = () => window.print();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'دریافت‌ها',
                href: receiptsIndex(),
            },
            {
                title: 'جزئیات دریافت',
                href: '/receipts',
            },
        ],
    },
});
</script>

<template>
    <Head :title="`دریافت ${receipt.receipt_number}`" />

    <div class="mx-auto w-full max-w-5xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="relative overflow-hidden rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8 print:border-none print:bg-white"
        >
            <div
                class="relative flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <HandCoins class="size-5" />
                        <span class="text-xs font-bold">رسید دریافت</span>
                        <span
                            class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-semibold"
                            :class="receiptStatus.badgeClass"
                        >
                            <component
                                :is="receiptStatus.icon"
                                class="size-3"
                            />
                            {{ receiptStatus.label }}
                        </span>
                    </div>
                    <h1
                        dir="ltr"
                        class="text-right font-mono text-2xl font-black sm:text-3xl"
                    >
                        {{ receipt.receipt_number }}
                    </h1>
                    <p class="mt-2 flex items-center gap-2 text-xs">
                        <CalendarDays class="size-4 text-muted-foreground" />
                        {{
                            date.format(
                                new Date(`${receipt.receipt_date}T00:00:00`),
                            )
                        }}
                    </p>
                </div>
                <div class="flex gap-2 print:hidden">
                    <Button
                        variant="outline"
                        class="rounded-xl"
                        @click="printReceipt"
                    >
                        <Printer class="size-4" />
                        چاپ
                    </Button>
                    <Button as-child variant="outline" class="rounded-xl">
                        <Link :href="createReceipt()">
                            <HandCoins class="size-4" />
                            دریافت جدید
                        </Link>
                    </Button>
                    <Button as-child variant="ghost" class="rounded-xl">
                        <Link :href="receiptsIndex()">
                            <ArrowRight class="size-4" />
                            بازگشت
                        </Link>
                    </Button>
                </div>
            </div>
        </section>

        <section
            class="grid gap-4 rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:grid-cols-3"
        >
            <div class="flex items-start gap-3">
                <span
                    class="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary"
                >
                    <UserRound class="size-5" />
                </span>
                <div>
                    <p class="text-[10px] text-muted-foreground">مشتری</p>
                    <p class="mt-1 text-sm font-bold">
                        {{ receipt.customer.name }}
                    </p>
                    <p class="mt-1 text-[10px] text-muted-foreground">
                        {{ receipt.customer.code }} ·
                        {{ receipt.customer.phone ?? 'بدون تلفن' }}
                    </p>
                </div>
            </div>
            <div>
                <p class="text-[10px] text-muted-foreground">روش دریافت</p>
                <p class="mt-2 flex items-center gap-2 text-sm font-bold">
                    <component
                        :is="methodMeta[receipt.payment_method].icon"
                        class="size-4 text-primary"
                    />
                    {{ methodMeta[receipt.payment_method].label }}
                </p>
                <p class="mt-1 text-[10px] text-muted-foreground">
                    {{ receipt.treasury_account ?? 'حساب پیش‌فرض' }}
                </p>
            </div>
            <div>
                <p class="text-[10px] text-muted-foreground">مبلغ دریافت</p>
                <p class="mt-2 text-xl font-black">
                    {{ money.format(Number(receipt.amount)) }}
                    <small class="text-[9px] font-medium">ریال</small>
                </p>
            </div>
        </section>

        <section
            class="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
        >
            <div class="border-b border-border/70 px-5 py-4">
                <h2 class="text-sm font-bold">فاکتورهای تسویه‌شده</h2>
            </div>
            <div class="divide-y divide-border/60">
                <div
                    v-for="allocation in receipt.allocations"
                    :key="allocation.id"
                    class="flex flex-col justify-between gap-3 px-5 py-4 sm:flex-row sm:items-center"
                >
                    <div>
                        <p
                            dir="ltr"
                            class="text-right font-mono text-xs font-bold"
                        >
                            {{ allocation.invoice.invoice_number }}
                        </p>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            تاریخ فاکتور:
                            {{
                                date.format(
                                    new Date(
                                        `${allocation.invoice.invoice_date}T00:00:00`,
                                    ),
                                )
                            }}
                        </p>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="text-left">
                            <p class="text-[9px] text-muted-foreground">
                                مبلغ تخصیص
                            </p>
                            <p class="mt-1 text-xs font-bold">
                                {{ money.format(Number(allocation.amount)) }}
                            </p>
                        </div>
                        <Button
                            as-child
                            variant="outline"
                            size="sm"
                            class="rounded-lg print:hidden"
                        >
                            <Link :href="showSale(allocation.invoice.id)">
                                مشاهده فاکتور
                            </Link>
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <section
            v-if="receipt.reference || receipt.description"
            class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
        >
            <p v-if="receipt.reference" class="text-xs">
                <span class="text-muted-foreground">کد مرجع:</span>
                {{ receipt.reference }}
            </p>
            <p v-if="receipt.description" class="mt-2 text-xs leading-7">
                {{ receipt.description }}
            </p>
        </section>

        <section
            class="flex items-start gap-2.5 rounded-2xl border p-4 print:hidden"
            :class="receiptStatus.panelClass"
        >
            <component :is="receiptStatus.icon" class="mt-0.5 size-4" />
            <p class="text-[10px] leading-6 text-muted-foreground">
                {{ receiptStatus.message }}
            </p>
        </section>
    </div>
</template>
