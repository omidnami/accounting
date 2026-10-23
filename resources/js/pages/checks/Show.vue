<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    Banknote,
    Building2,
    CalendarClock,
    CalendarDays,
    Check,
    CircleX,
    FileText,
    Landmark,
    ReceiptText,
    RotateCcw,
    UserRound,
    WalletCards,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import JalaliDatePicker from '@/components/JalaliDatePicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    bounce as bounceCheck,
    index as checksIndex,
    settle as settleCheck,
} from '@/routes/checks';
import {
    create as createPayment,
    show as showPayment,
} from '@/routes/payments';
import { show as showPurchase } from '@/routes/purchases';
import {
    create as createReceipt,
    show as showReceipt,
} from '@/routes/receipts';
import { show as showSale } from '@/routes/sales';

type CheckType = 'receivable' | 'payable';
type CheckStatus = 'pending' | 'collected' | 'spent' | 'bounced';
type TreasuryKind = 'receipt' | 'payment';

type Entry = {
    id: number;
    number: string;
    date: string;
    description: string | null;
    status: string;
};

type TreasuryDocument = {
    kind: TreasuryKind;
    id: number;
    number: string;
    date: string;
    amount: string;
    status: string;
    invoices: Array<{
        id: number;
        invoice_number: string;
        amount: string;
    }>;
};

const props = defineProps<{
    check: {
        id: number;
        document_number: string | null;
        check_number: string;
        bank_name: string;
        branch_name: string | null;
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
        description: string | null;
        creator: string | null;
        created_at: string | null;
        settled_at: string | null;
        bounced_at: string | null;
        treasury_document: TreasuryDocument | null;
        settlement_treasury_account: string | null;
        settlement_treasury_account_code: string | null;
        entries: {
            settlement: Entry | null;
            bounce: Entry | null;
        };
    };
    bank_accounts: Array<{
        id: number;
        name: string;
        code: string;
        is_default: boolean;
    }>;
    defaults: {
        action_date: string;
    };
}>();

const settlementForm = useForm({
    settlement_date: props.defaults.action_date,
    treasury_account_id:
        props.bank_accounts
            .find((account) => account.is_default)
            ?.id.toString() ??
        props.bank_accounts[0]?.id.toString() ??
        '',
});
const bounceForm = useForm({
    bounce_date: props.defaults.action_date,
});
const showBounce = ref(false);
const money = new Intl.NumberFormat('fa-IR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});
const date = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
});
const dateTime = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});
const typeMeta: Record<CheckType, { label: string; className: string }> = {
    receivable: {
        label: 'چک دریافتی',
        className: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
    },
    payable: {
        label: 'چک پرداختی',
        className: 'bg-violet-500/10 text-violet-700 dark:text-violet-300',
    },
};
const statusMeta: Record<
    CheckStatus,
    {
        label: string;
        icon: typeof Check;
        badgeClass: string;
        panelClass: string;
        message: string;
    }
> = {
    pending: {
        label: 'در انتظار',
        icon: CalendarClock,
        badgeClass: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
        panelClass:
            'border-amber-500/20 bg-amber-500/[.055] text-amber-800 dark:text-amber-200',
        message:
            'چک ثبت شده اما هنوز وصول یا خرج نشده است؛ حساب بانکی تغییر نکرده است.',
    },
    collected: {
        label: 'وصول‌شده',
        icon: Check,
        badgeClass: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        panelClass:
            'border-emerald-500/20 bg-emerald-500/[.055] text-emerald-800 dark:text-emerald-200',
        message:
            'وصول چک در حساب بانکی ثبت و سند تسویه به‌صورت متوازن ایجاد شده است.',
    },
    spent: {
        label: 'خرج‌شده',
        icon: Banknote,
        badgeClass: 'bg-primary/10 text-primary',
        panelClass: 'border-primary/20 bg-primary/[.055] text-primary',
        message:
            'خرج چک پرداختی ثبت شده و تعهد بانکی آن به‌صورت قطعی درآمده است.',
    },
    bounced: {
        label: 'برگشتی',
        icon: CircleX,
        badgeClass: 'bg-red-500/10 text-red-700 dark:text-red-300',
        panelClass:
            'border-red-500/20 bg-red-500/[.055] text-red-800 dark:text-red-200',
        message:
            'چک برگشت خورده و مطالبات یا بدهی مربوطه با سند اصلاحی بازگردانده شده است.',
    },
};
const status = computed(() => statusMeta[props.check.status]);
const isPending = computed(() => props.check.status === 'pending');
const isLinked = computed(
    () => props.check.treasury_document?.status === 'confirmed',
);
const actionLabel = computed(() =>
    props.check.type === 'receivable' ? 'ثبت وصول' : 'ثبت خرج',
);
const settlementCheckError = computed(
    () =>
        (settlementForm.errors as Record<string, string>).check ||
        (props.bank_accounts.length === 0
            ? 'حساب بانکی فعال برای تسویه وجود ندارد.'
            : ''),
);
const bounceCheckError = computed(
    () => (bounceForm.errors as Record<string, string>).check ?? '',
);
const partyIcon = computed(() =>
    props.check.type === 'receivable' ? UserRound : Building2,
);
const treasuryDocumentUrl = computed(() => {
    const document = props.check.treasury_document;

    if (!document) {
        return null;
    }

    return document.kind === 'receipt'
        ? showReceipt(document.id)
        : showPayment(document.id);
});
const invoiceUrl = (invoiceId: number) =>
    props.check.treasury_document?.kind === 'receipt'
        ? showSale(invoiceId)
        : showPurchase(invoiceId);
const linkDocumentUrl = computed(() => {
    const partyId = props.check.party.id;

    if (partyId === null) {
        return null;
    }

    return props.check.type === 'receivable'
        ? createReceipt.url({ query: { customer_id: partyId } })
        : createPayment.url({ query: { supplier_id: partyId } });
});
const submitSettlement = () => {
    settlementForm.post(settleCheck(props.check.id).url, {
        preserveScroll: true,
    });
};
const submitBounce = () => {
    if (
        !window.confirm(
            'برگشت چک سند اصلاحی ایجاد می‌کند. از ثبت تاریخ و برگشت چک مطمئن هستید؟',
        )
    ) {
        return;
    }

    bounceForm.post(bounceCheck(props.check.id).url, {
        preserveScroll: true,
    });
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'چک‌ها', href: checksIndex() },
            { title: 'جزئیات چک', href: '/checks' },
        ],
    },
});
</script>

<template>
    <Head :title="`چک ${check.document_number ?? check.check_number}`" />

    <div class="mx-auto w-full max-w-6xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="relative overflow-hidden rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="relative flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <span
                            class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-semibold"
                            :class="typeMeta[check.type].className"
                        >
                            <ReceiptText class="size-3" />
                            {{ typeMeta[check.type].label }}
                        </span>
                        <span
                            class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-semibold"
                            :class="status.badgeClass"
                        >
                            <component :is="status.icon" class="size-3" />
                            {{ status.label }}
                        </span>
                    </div>
                    <h1
                        dir="ltr"
                        class="text-right font-mono text-2xl font-black sm:text-3xl"
                    >
                        {{ check.document_number ?? check.check_number }}
                    </h1>
                    <p
                        class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted-foreground"
                    >
                        <span class="flex items-center gap-1.5">
                            <Landmark class="size-3.5" />
                            {{ check.bank_name }}
                            · شماره بانکی {{ check.check_number }}
                            <template v-if="check.branch_name">
                                · {{ check.branch_name }}
                            </template>
                        </span>
                        <span class="flex items-center gap-1.5">
                            <CalendarDays class="size-3.5" />
                            سررسید
                            {{
                                date.format(
                                    new Date(`${check.due_date}T00:00:00`),
                                )
                            }}
                        </span>
                    </p>
                </div>
                <Button as-child variant="outline" class="rounded-xl">
                    <Link :href="checksIndex()">
                        <ArrowRight class="size-4" />
                        بازگشت به چک‌ها
                    </Link>
                </Button>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <article
                class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:col-span-2"
            >
                <div class="flex items-center justify-between">
                    <p class="text-[10px] text-muted-foreground">مبلغ چک</p>
                    <WalletCards class="size-4 text-primary" />
                </div>
                <p class="mt-3 text-2xl font-black">
                    {{ money.format(Number(check.amount)) }}
                    <small class="text-[10px] font-medium">ریال</small>
                </p>
                <p
                    v-if="check.settlement_treasury_account"
                    class="mt-2 text-[10px] text-muted-foreground"
                >
                    حساب تسویه:
                    {{ check.settlement_treasury_account }}
                    · {{ check.settlement_treasury_account_code }}
                </p>
            </article>
            <article
                class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
            >
                <p class="text-[10px] text-muted-foreground">تاریخ صدور</p>
                <p class="mt-3 text-sm font-bold">
                    {{
                        check.issue_date
                            ? date.format(
                                  new Date(`${check.issue_date}T00:00:00`),
                              )
                            : 'ثبت نشده'
                    }}
                </p>
            </article>
            <article
                class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
            >
                <p class="text-[10px] text-muted-foreground">ثبت‌کننده</p>
                <p class="mt-3 text-sm font-bold">
                    {{ check.creator ?? 'سیستم' }}
                </p>
                <p class="mt-1 text-[9px] text-muted-foreground">
                    {{
                        check.created_at
                            ? dateTime.format(new Date(check.created_at))
                            : 'بدون زمان'
                    }}
                </p>
            </article>
        </section>

        <section class="grid gap-5 lg:grid-cols-[1.15fr_.85fr]">
            <div class="space-y-5">
                <article
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:p-6"
                >
                    <div class="flex gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                        >
                            <component :is="partyIcon" class="size-5" />
                        </span>
                        <div>
                            <p class="text-[10px] text-muted-foreground">
                                {{
                                    check.type === 'receivable'
                                        ? 'مشتری'
                                        : 'تأمین‌کننده'
                                }}
                            </p>
                            <p class="mt-1 text-sm font-bold">
                                {{ check.party.name ?? 'نامشخص' }}
                            </p>
                            <p class="mt-1 text-[10px] text-muted-foreground">
                                {{ check.party.code ?? 'بدون کد' }}
                            </p>
                        </div>
                    </div>
                    <p
                        v-if="check.description"
                        class="mt-5 border-t pt-4 text-xs leading-7 text-muted-foreground"
                    >
                        {{ check.description }}
                    </p>
                </article>

                <article
                    class="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
                >
                    <div
                        class="flex items-center justify-between border-b border-border/70 px-5 py-4"
                    >
                        <div>
                            <h2 class="text-sm font-bold">سند خزانه مرتبط</h2>
                            <p class="mt-1 text-[10px] text-muted-foreground">
                                چک باید پیش از وصول یا خرج به یک دریافت/پرداخت
                                تأییدشده متصل باشد.
                            </p>
                        </div>
                        <FileText class="size-4 text-muted-foreground" />
                    </div>
                    <div v-if="check.treasury_document" class="p-5">
                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <div>
                                <p
                                    dir="ltr"
                                    class="text-right font-mono text-sm font-bold"
                                >
                                    {{ check.treasury_document.number }}
                                </p>
                                <p
                                    class="mt-1 text-[10px] text-muted-foreground"
                                >
                                    {{
                                        check.treasury_document.kind ===
                                        'receipt'
                                            ? 'دریافت'
                                            : 'پرداخت'
                                    }}
                                    ·
                                    {{
                                        date.format(
                                            new Date(
                                                `${check.treasury_document.date}T00:00:00`,
                                            ),
                                        )
                                    }}
                                </p>
                            </div>
                            <Button
                                as-child
                                variant="outline"
                                size="sm"
                                class="rounded-xl"
                            >
                                <Link :href="treasuryDocumentUrl!">
                                    مشاهده سند
                                </Link>
                            </Button>
                        </div>
                        <div
                            v-if="check.treasury_document.invoices.length"
                            class="mt-4 space-y-2 border-t pt-4"
                        >
                            <div
                                v-for="invoice in check.treasury_document
                                    .invoices"
                                :key="invoice.id"
                                class="flex items-center justify-between gap-4 rounded-xl bg-muted/35 px-3 py-2.5"
                            >
                                <Link
                                    :href="invoiceUrl(invoice.id)"
                                    class="font-mono text-[11px] font-semibold text-primary hover:underline"
                                >
                                    {{ invoice.invoice_number }}
                                </Link>
                                <span class="text-[10px] font-bold">
                                    {{ money.format(Number(invoice.amount)) }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div v-else class="px-5 py-10 text-center">
                        <AlertTriangle
                            class="mx-auto size-8 text-amber-500/70"
                        />
                        <p class="mt-3 text-xs font-semibold">
                            این چک هنوز به دریافت یا پرداخت متصل نیست
                        </p>
                        <p
                            class="mx-auto mt-2 max-w-md text-[10px] leading-6 text-muted-foreground"
                        >
                            در فرم دریافت یا پرداخت، روش «چکی» را انتخاب کنید و
                            این چک را به فاکتورهای باز همان طرف‌حساب تخصیص دهید.
                        </p>
                        <Button
                            v-if="linkDocumentUrl"
                            as-child
                            class="mt-4 rounded-xl"
                        >
                            <Link :href="linkDocumentUrl">
                                {{
                                    check.type === 'receivable'
                                        ? 'ثبت دریافت چکی'
                                        : 'ثبت پرداخت چکی'
                                }}
                            </Link>
                        </Button>
                    </div>
                </article>

                <article
                    v-if="check.entries.settlement || check.entries.bounce"
                    class="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
                >
                    <div class="border-b border-border/70 px-5 py-4">
                        <h2 class="text-sm font-bold">اسناد حسابداری چک</h2>
                    </div>
                    <div class="divide-y divide-border/60">
                        <div
                            v-for="entry in [
                                check.entries.settlement,
                                check.entries.bounce,
                            ].filter(Boolean)"
                            :key="entry!.id"
                            class="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                        >
                            <div>
                                <p
                                    dir="ltr"
                                    class="text-right font-mono text-xs font-bold"
                                >
                                    {{ entry!.number }}
                                </p>
                                <p
                                    class="mt-1 text-[10px] text-muted-foreground"
                                >
                                    {{ entry!.description }}
                                </p>
                            </div>
                            <span
                                class="rounded-full bg-emerald-500/10 px-2.5 py-1 text-[10px] font-semibold text-emerald-700 dark:text-emerald-300"
                            >
                                {{ entry!.status }} ·
                                {{
                                    date.format(
                                        new Date(`${entry!.date}T00:00:00`),
                                    )
                                }}
                            </span>
                        </div>
                    </div>
                </article>
            </div>

            <div class="space-y-5">
                <article
                    v-if="isPending"
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <h2 class="text-sm font-bold">
                        {{ check.type === 'receivable' ? 'وصول' : 'خرج' }} چک
                    </h2>
                    <p class="mt-1 text-[10px] leading-5 text-muted-foreground">
                        تاریخ عملیات می‌تواند در دوره مالی جاری یا بعد باشد؛ سند
                        در همان دوره ثبت می‌شود.
                    </p>

                    <form
                        class="mt-5 space-y-4"
                        @submit.prevent="submitSettlement"
                    >
                        <div class="grid gap-2">
                            <Label for="settlement_date">
                                تاریخ {{ actionLabel }} *
                            </Label>
                            <JalaliDatePicker
                                id="settlement_date"
                                v-model="settlementForm.settlement_date"
                            />
                            <InputError
                                :message="settlementForm.errors.settlement_date"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="treasury_account_id"
                                >حساب بانکی *</Label
                            >
                            <select
                                id="treasury_account_id"
                                v-model="settlementForm.treasury_account_id"
                                class="h-11 rounded-xl border border-input bg-background px-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                            >
                                <option value="">حساب پیش‌فرض</option>
                                <option
                                    v-for="account in bank_accounts"
                                    :key="account.id"
                                    :value="account.id.toString()"
                                >
                                    {{ account.name }} · {{ account.code }}
                                </option>
                            </select>
                            <InputError
                                :message="
                                    settlementForm.errors.treasury_account_id
                                "
                            />
                        </div>
                        <InputError :message="settlementCheckError" />
                        <Button
                            type="submit"
                            class="h-11 w-full rounded-xl"
                            :disabled="
                                settlementForm.processing ||
                                !isLinked ||
                                !bank_accounts.length
                            "
                        >
                            <Spinner v-if="settlementForm.processing" />
                            <Check v-else class="size-4" />
                            {{ actionLabel }}
                        </Button>
                        <p
                            v-if="!isLinked"
                            class="text-[10px] leading-5 text-amber-700 dark:text-amber-300"
                        >
                            ابتدا چک را از طریق فرم دریافت/پرداخت به سند
                            تأییدشده متصل کنید.
                        </p>
                    </form>
                </article>

                <article
                    v-if="isPending"
                    class="rounded-2xl border border-red-500/15 bg-red-500/[.035] p-5"
                >
                    <div class="flex gap-3">
                        <RotateCcw
                            class="mt-0.5 size-4 shrink-0 text-red-600"
                        />
                        <div>
                            <h2
                                class="text-sm font-bold text-red-700 dark:text-red-300"
                            >
                                برگشت چک
                            </h2>
                            <p
                                class="mt-1 text-[10px] leading-5 text-muted-foreground"
                            >
                                برگشت چک، سند خزانه را برگشتی می‌کند و مطالبه یا
                                بدهی طرف‌حساب را دوباره باز می‌کند.
                            </p>
                        </div>
                    </div>
                    <Button
                        v-if="!showBounce"
                        type="button"
                        variant="outline"
                        class="mt-4 w-full rounded-xl border-red-500/30 text-red-700 hover:bg-red-500/10 dark:text-red-300"
                        :disabled="!isLinked"
                        @click="showBounce = true"
                    >
                        ثبت برگشت
                    </Button>
                    <form
                        v-else
                        class="mt-4 space-y-3"
                        @submit.prevent="submitBounce"
                    >
                        <Label for="bounce_date">تاریخ برگشت *</Label>
                        <JalaliDatePicker
                            id="bounce_date"
                            v-model="bounceForm.bounce_date"
                        />
                        <InputError :message="bounceForm.errors.bounce_date" />
                        <InputError :message="bounceCheckError" />
                        <div class="flex gap-2">
                            <Button
                                type="submit"
                                variant="destructive"
                                class="flex-1 rounded-xl"
                                :disabled="bounceForm.processing"
                            >
                                <Spinner v-if="bounceForm.processing" />
                                <CircleX v-else class="size-4" />
                                تأیید برگشت
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                @click="showBounce = false"
                            >
                                انصراف
                            </Button>
                        </div>
                    </form>
                </article>

                <section
                    class="rounded-2xl border p-4"
                    :class="status.panelClass"
                >
                    <div class="flex gap-2">
                        <component :is="status.icon" class="mt-0.5 size-4" />
                        <div>
                            <p class="text-xs font-bold">{{ status.label }}</p>
                            <p class="mt-1 text-[10px] leading-6">
                                {{ status.message }}
                            </p>
                            <p
                                v-if="check.settled_at"
                                class="mt-2 text-[9px] opacity-75"
                            >
                                زمان تسویه:
                                {{
                                    dateTime.format(new Date(check.settled_at))
                                }}
                            </p>
                            <p
                                v-if="check.bounced_at"
                                class="mt-2 text-[9px] opacity-75"
                            >
                                زمان برگشت:
                                {{
                                    dateTime.format(new Date(check.bounced_at))
                                }}
                            </p>
                        </div>
                    </div>
                </section>
            </div>
        </section>
    </div>
</template>
