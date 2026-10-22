<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Banknote,
    Building2,
    CalendarDays,
    Check,
    CircleAlert,
    CreditCard,
    HandCoins,
    Landmark,
    Printer,
} from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    create as createPayment,
    index as paymentsIndex,
} from '@/routes/payments';
import { show as showPurchase } from '@/routes/purchases';

type Method = 'cash' | 'bank' | 'card' | 'check';
type Status = 'pending' | 'confirmed' | 'bounced' | 'cancelled';

const props = defineProps<{
    payment: {
        id: number;
        payment_number: string;
        payment_date: string;
        amount: string;
        payment_method: Method;
        status: Status;
        reference: string | null;
        description: string | null;
        treasury_account: string | null;
        supplier: {
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

const money = new Intl.NumberFormat('fa-IR');
const date = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
});
const methodMeta = {
    cash: { label: 'نقدی', icon: Banknote },
    bank: { label: 'بانکی', icon: Landmark },
    card: { label: 'کارتی', icon: CreditCard },
    check: { label: 'چکی', icon: HandCoins },
};
const statusMeta = {
    pending: {
        label: 'در انتظار',
        icon: CircleAlert,
        className: 'bg-amber-500/10 text-amber-700',
        message: 'این پرداخت هنوز نهایی نشده است.',
    },
    confirmed: {
        label: 'تأییدشده',
        icon: Check,
        className: 'bg-emerald-500/10 text-emerald-700',
        message:
            'این پرداخت تأیید شده، به فاکتورهای بالا تخصیص یافته و سند آن ثبت شده است.',
    },
    bounced: {
        label: 'برگشتی',
        icon: CircleAlert,
        className: 'bg-red-500/10 text-red-700',
        message: 'این پرداخت برگشت خورده و نیازمند سند اصلاحی است.',
    },
    cancelled: {
        label: 'لغوشده',
        icon: CircleAlert,
        className: 'bg-muted text-muted-foreground',
        message: 'این پرداخت لغو شده و اثر مالی فعالی ندارد.',
    },
};
const status = computed(() => statusMeta[props.payment.status]);
const printReceipt = () => window.print();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'پرداخت‌ها', href: paymentsIndex() },
            { title: 'جزئیات پرداخت', href: '/payments' },
        ],
    },
});
</script>

<template>
    <Head :title="`پرداخت ${payment.payment_number}`" />

    <div class="mx-auto w-full max-w-5xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="rounded-3xl border bg-card p-6 sm:p-8 print:border-none"
        >
            <div class="flex flex-col justify-between gap-4 sm:flex-row">
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <HandCoins class="size-5" />
                        <span class="text-xs font-bold">رسید پرداخت</span>
                        <span
                            class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-semibold"
                            :class="status.className"
                        >
                            <component :is="status.icon" class="size-3" />
                            {{ status.label }}
                        </span>
                    </div>
                    <h1 class="font-mono text-2xl font-black">
                        {{ payment.payment_number }}
                    </h1>
                    <p class="mt-2 flex items-center gap-2 text-xs">
                        <CalendarDays class="size-4" />
                        {{
                            date.format(
                                new Date(`${payment.payment_date}T00:00:00`),
                            )
                        }}
                    </p>
                </div>
                <div class="flex gap-2 print:hidden">
                    <Button variant="outline" @click="printReceipt">
                        <Printer class="size-4" /> چاپ
                    </Button>
                    <Button as-child variant="outline">
                        <Link :href="createPayment()">پرداخت جدید</Link>
                    </Button>
                    <Button as-child variant="ghost">
                        <Link :href="paymentsIndex()">
                            <ArrowRight class="size-4" /> بازگشت
                        </Link>
                    </Button>
                </div>
            </div>
        </section>

        <section
            class="grid gap-4 rounded-2xl border bg-card p-5 sm:grid-cols-3"
        >
            <div class="flex gap-3">
                <Building2 class="size-5 text-primary" />
                <div>
                    <p class="text-[10px] text-muted-foreground">تأمین‌کننده</p>
                    <p class="mt-1 text-sm font-bold">
                        {{ payment.supplier.name }}
                    </p>
                    <p class="mt-1 text-[10px] text-muted-foreground">
                        {{ payment.supplier.code }} ·
                        {{ payment.supplier.phone ?? 'بدون تلفن' }}
                    </p>
                </div>
            </div>
            <div>
                <p class="text-[10px] text-muted-foreground">روش پرداخت</p>
                <p class="mt-2 flex items-center gap-2 text-sm font-bold">
                    <component
                        :is="methodMeta[payment.payment_method].icon"
                        class="size-4"
                    />
                    {{ methodMeta[payment.payment_method].label }}
                </p>
                <p class="mt-1 text-[10px] text-muted-foreground">
                    {{ payment.treasury_account ?? 'حساب پیش‌فرض' }}
                </p>
            </div>
            <div>
                <p class="text-[10px] text-muted-foreground">مبلغ پرداخت</p>
                <p class="mt-2 text-xl font-black">
                    {{ money.format(Number(payment.amount)) }}
                    <small class="text-[9px]">ریال</small>
                </p>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border bg-card">
            <div class="border-b px-5 py-4">
                <h2 class="text-sm font-bold">فاکتورهای تسویه‌شده</h2>
            </div>
            <div class="divide-y">
                <div
                    v-for="allocation in payment.allocations"
                    :key="allocation.id"
                    class="flex flex-col justify-between gap-3 px-5 py-4 sm:flex-row sm:items-center"
                >
                    <div>
                        <p class="font-mono text-xs font-bold">
                            {{ allocation.invoice.invoice_number }}
                        </p>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            تاریخ خرید:
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
                        <p class="text-xs font-bold">
                            {{ money.format(Number(allocation.amount)) }}
                        </p>
                        <Button
                            as-child
                            variant="outline"
                            size="sm"
                            class="print:hidden"
                        >
                            <Link :href="showPurchase(allocation.invoice.id)">
                                مشاهده خرید
                            </Link>
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <section
            v-if="payment.reference || payment.description"
            class="rounded-2xl border bg-card p-5"
        >
            <p v-if="payment.reference" class="text-xs">
                <span class="text-muted-foreground">کد مرجع:</span>
                {{ payment.reference }}
            </p>
            <p v-if="payment.description" class="mt-2 text-xs leading-7">
                {{ payment.description }}
            </p>
        </section>

        <section class="rounded-2xl border p-4" :class="status.className">
            <div class="flex gap-2">
                <component :is="status.icon" class="mt-0.5 size-4" />
                <p class="text-[10px] leading-6">{{ status.message }}</p>
            </div>
        </section>
    </div>
</template>
