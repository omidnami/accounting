<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BarChart3,
    Building2,
    CalendarClock,
    Download,
    Printer,
    ReceiptText,
    UsersRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import JalaliDatePicker from '@/components/JalaliDatePicker.vue';
import { operations as operationsReport } from '@/routes/reports';
import { operations as exportOperations } from '@/routes/reports/export';

type StatementRow = {
    entry_id: number;
    entry_number: string;
    date: string;
    description: string;
    account_code: string;
    account_name: string;
    debit: string;
    credit: string;
    balance: string;
};
type AgingRow = {
    invoice_id: number;
    invoice_number: string;
    invoice_date: string;
    due_date: string;
    party: { id: number; code: string; name: string };
    outstanding: string;
    days_overdue: number;
    bucket: string;
};
type AgingReport = {
    rows: AgingRow[];
    buckets: Record<string, string>;
    total: string;
};
type SalesReport = {
    summary: {
        revenue: string;
        cogs: string;
        gross_profit: string;
        gross_margin: string;
        cogs_complete: boolean;
        incomplete_cogs_count: number;
        sales_invoices: number;
        return_invoices: number;
    };
    daily: Array<{
        date: string;
        revenue: string;
        cogs: string;
        gross_profit: string;
        quantity: string;
        cogs_complete: boolean;
    }>;
    products: Array<{
        product_id: number;
        code: string;
        name: string;
        unit: string;
        quantity: string;
        revenue: string;
        cogs: string;
        gross_profit: string;
        gross_margin: string;
        cogs_complete: boolean;
    }>;
};

const props = defineProps<{
    filters: {
        from: string;
        to: string;
        branch_id: number | null;
        party_id: number | null;
    };
    branches: Array<{ id: number; name: string; is_active: boolean }>;
    parties: Array<{
        id: number;
        code: string;
        name: string;
        kind: 'customer' | 'supplier';
    }>;
    statement: {
        party: { id: number; code: string; name: string; kind: string };
        opening_balance: string;
        debit_total: string;
        credit_total: string;
        closing_balance: string;
        rows: StatementRow[];
    } | null;
    receivablesAging: AgingReport;
    payablesAging: AgingReport;
    sales: SalesReport;
}>();

const tabs = [
    { key: 'statement', label: 'صورتحساب طرف‌حساب', icon: UsersRound },
    { key: 'aging', label: 'Aging مطالبات و بدهی‌ها', icon: CalendarClock },
    { key: 'sales', label: 'فروش و سود کالا', icon: BarChart3 },
] as const;
const activeTab = ref<(typeof tabs)[number]['key']>('statement');
const agingSide = ref<'receivable' | 'payable'>('receivable');
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const branchId = ref(props.filters.branch_id?.toString() ?? '');
const partyId = ref(props.filters.party_id?.toString() ?? '');
const money = new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 2 });
const quantity = new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 3 });
const date = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    timeZone: 'UTC',
});
const activeAging = computed(() =>
    agingSide.value === 'receivable'
        ? props.receivablesAging
        : props.payablesAging,
);
const activeReportLabel = computed(() => {
    if (activeTab.value === 'statement') {
        return 'صورتحساب طرف‌حساب';
    }

    if (activeTab.value === 'aging') {
        return agingSide.value === 'receivable'
            ? 'Aging مطالبات مشتریان'
            : 'Aging بدهی تأمین‌کنندگان';
    }

    return 'فروش روزانه و سود کالا';
});
const selectedBranchName = computed(
    () =>
        props.branches.find((branch) => branch.id === props.filters.branch_id)
            ?.name ?? 'همه شعبه‌ها',
);
const selectedPartyName = computed(() => {
    const party = props.parties.find(
        (item) => item.id === props.filters.party_id,
    );

    return party ? `${party.code} — ${party.name}` : 'انتخاب‌نشده';
});
const bucketLabels: Record<string, string> = {
    current: 'سررسید نشده',
    days_1_30: '۱ تا ۳۰ روز',
    days_31_60: '۳۱ تا ۶۰ روز',
    days_61_90: '۶۱ تا ۹۰ روز',
    over_90: 'بیش از ۹۰ روز',
};
const bucketKeys = [
    'current',
    'days_1_30',
    'days_31_60',
    'days_61_90',
    'over_90',
];
const formatDate = (value: string) =>
    date.format(new Date(`${value}T12:00:00Z`));
const applyFilters = () => {
    router.get(
        operationsReport(),
        {
            from: from.value,
            to: to.value,
            branch_id: branchId.value || undefined,
            party_id: partyId.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
const exportHref = (report: string) =>
    exportOperations({
        query: {
            report,
            from: props.filters.from,
            to: props.filters.to,
            branch_id: props.filters.branch_id ?? undefined,
            party_id: props.filters.party_id ?? undefined,
        },
    }).url;
const printReport = () => window.print();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'گزارش‌های عملیاتی', href: operationsReport() }],
    },
});
</script>

<template>
    <Head title="گزارش‌های عملیاتی" />

    <div
        class="print-report mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8"
    >
        <section
            class="rounded-3xl border border-primary/10 bg-card p-6 shadow-sm sm:p-8"
        >
            <div class="mb-3 flex items-center gap-2 text-primary">
                <BarChart3 class="size-5" />
                <span class="text-xs font-bold">تصویر عملیاتی فروشگاه</span>
            </div>
            <h1 class="text-2xl font-black sm:text-3xl">
                طرف‌حساب‌ها، Aging، فروش و سود
            </h1>
            <p class="mt-2 max-w-3xl text-sm leading-7 text-muted-foreground">
                صورتحساب از اسناد Posted، Aging از مانده واقعی فاکتورها و سود
                کالا از COGS snapshot همان فروش محاسبه می‌شود.
            </p>
            <div class="mt-5 flex flex-wrap gap-2 print:hidden">
                <template v-if="activeTab === 'statement'">
                    <Button
                        v-if="statement"
                        as-child
                        variant="outline"
                        class="rounded-xl"
                    >
                        <a :href="exportHref('statement')"
                            ><Download class="size-4" /> CSV (Excel)</a
                        >
                    </Button>
                </template>
                <template v-else-if="activeTab === 'aging'">
                    <Button as-child variant="outline" class="rounded-xl">
                        <a
                            :href="
                                exportHref(
                                    agingSide === 'receivable'
                                        ? 'aging_receivable'
                                        : 'aging_payable',
                                )
                            "
                        >
                            <Download class="size-4" /> CSV (Excel)
                        </a>
                    </Button>
                </template>
                <template v-else>
                    <Button as-child variant="outline" class="rounded-xl">
                        <a :href="exportHref('sales_daily')"
                            ><Download class="size-4" /> CSV روزانه (Excel)</a
                        >
                    </Button>
                    <Button as-child variant="outline" class="rounded-xl">
                        <a :href="exportHref('sales_products')"
                            ><Download class="size-4" /> CSV کالا و سود
                            (Excel)</a
                        >
                    </Button>
                </template>
                <Button type="button" class="rounded-xl" @click="printReport">
                    <Printer class="size-4" /> چاپ / PDF
                </Button>
            </div>
        </section>

        <form
            class="grid gap-3 rounded-2xl border bg-card p-4 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_1fr_1.5fr_auto] print:hidden"
            @submit.prevent="applyFilters"
        >
            <JalaliDatePicker v-model="from" />
            <JalaliDatePicker v-model="to" />
            <label class="relative">
                <Building2
                    class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                />
                <select
                    v-model="branchId"
                    class="h-11 w-full appearance-none rounded-xl border bg-background pr-9 pl-3 text-sm"
                >
                    <option value="">همه شعبه‌ها</option>
                    <option
                        v-for="branch in branches"
                        :key="branch.id"
                        :value="branch.id"
                    >
                        {{ branch.name }}
                        {{ branch.is_active ? '' : '(غیرفعال)' }}
                    </option>
                </select>
            </label>
            <select
                v-model="partyId"
                class="h-11 rounded-xl border bg-background px-3 text-sm"
            >
                <option
                    v-for="party in parties"
                    :key="party.id"
                    :value="party.id"
                >
                    {{ party.name }} — {{ party.code }}
                </option>
            </select>
            <Button type="submit" class="h-11 rounded-xl px-5">
                اعمال فیلتر
                <ArrowLeft class="size-4" />
            </Button>
        </form>

        <div
            class="flex gap-2 overflow-x-auto rounded-2xl border bg-card p-2 print:hidden"
        >
            <button
                v-for="tab in tabs"
                :key="tab.key"
                type="button"
                class="flex shrink-0 items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold"
                :class="
                    activeTab === tab.key
                        ? 'bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:bg-muted'
                "
                @click="activeTab = tab.key"
            >
                <component :is="tab.icon" class="size-4" />
                {{ tab.label }}
            </button>
        </div>

        <section
            class="hidden rounded-2xl border bg-card p-4 text-xs print:block"
        >
            <div class="grid gap-3 md:grid-cols-2">
                <p>
                    <span class="font-bold">گزارش:</span>
                    {{ activeReportLabel }}
                </p>
                <p>
                    <span class="font-bold">بازه:</span>
                    {{ formatDate(props.filters.from) }} تا
                    {{ formatDate(props.filters.to) }}
                </p>
                <p>
                    <span class="font-bold">شعبه:</span>
                    {{ selectedBranchName }}
                </p>
                <p v-if="activeTab === 'statement'">
                    <span class="font-bold">طرف‌حساب:</span>
                    {{ selectedPartyName }}
                </p>
            </div>
        </section>

        <template v-if="activeTab === 'statement'">
            <template v-if="statement">
                <section class="grid gap-4 sm:grid-cols-4">
                    <article class="rounded-2xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            مانده اول دوره
                        </p>
                        <p class="mt-2 text-lg font-black">
                            {{
                                money.format(Number(statement.opening_balance))
                            }}
                        </p>
                    </article>
                    <article class="rounded-2xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">گردش بدهکار</p>
                        <p class="mt-2 text-lg font-black">
                            {{ money.format(Number(statement.debit_total)) }}
                        </p>
                    </article>
                    <article class="rounded-2xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            گردش بستانکار
                        </p>
                        <p class="mt-2 text-lg font-black">
                            {{ money.format(Number(statement.credit_total)) }}
                        </p>
                    </article>
                    <article
                        class="rounded-2xl border border-primary/20 bg-primary/5 p-4"
                    >
                        <p class="text-xs text-muted-foreground">
                            مانده پایان دوره
                        </p>
                        <p class="mt-2 text-lg font-black text-primary">
                            {{
                                money.format(Number(statement.closing_balance))
                            }}
                        </p>
                    </article>
                </section>
                <section class="overflow-hidden rounded-2xl border bg-card">
                    <div class="border-b px-5 py-4">
                        <h2 class="text-sm font-bold">
                            {{ statement.party.name }} —
                            {{ statement.party.code }}
                        </h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[900px] text-right text-sm">
                            <thead
                                class="bg-muted/35 text-[11px] text-muted-foreground"
                            >
                                <tr>
                                    <th class="px-4 py-3">تاریخ</th>
                                    <th class="px-4 py-3">سند</th>
                                    <th class="px-4 py-3">شرح</th>
                                    <th class="px-4 py-3">حساب</th>
                                    <th class="px-4 py-3">بدهکار</th>
                                    <th class="px-4 py-3">بستانکار</th>
                                    <th class="px-5 py-3">مانده</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="row in statement.rows"
                                    :key="`${row.entry_id}-${row.balance}`"
                                >
                                    <td class="px-4 py-3 text-xs">
                                        {{ formatDate(row.date) }}
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs">
                                        {{ row.entry_number }}
                                    </td>
                                    <td
                                        class="max-w-72 px-4 py-3 text-xs text-muted-foreground"
                                    >
                                        {{ row.description }}
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        {{ row.account_code }} —
                                        {{ row.account_name }}
                                    </td>
                                    <td class="px-4 py-3 text-xs tabular-nums">
                                        {{ money.format(Number(row.debit)) }}
                                    </td>
                                    <td class="px-4 py-3 text-xs tabular-nums">
                                        {{ money.format(Number(row.credit)) }}
                                    </td>
                                    <td
                                        class="px-5 py-3 text-xs font-bold tabular-nums"
                                    >
                                        {{ money.format(Number(row.balance)) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>
            <p
                v-else
                class="rounded-2xl border bg-card py-16 text-center text-sm text-muted-foreground"
            >
                طرف‌حسابی برای گزارش وجود ندارد.
            </p>
        </template>

        <template v-else-if="activeTab === 'aging'">
            <div class="flex gap-2 print:hidden">
                <Button
                    :variant="
                        agingSide === 'receivable' ? 'default' : 'outline'
                    "
                    @click="agingSide = 'receivable'"
                >
                    مطالبات مشتریان
                </Button>
                <Button
                    :variant="agingSide === 'payable' ? 'default' : 'outline'"
                    @click="agingSide = 'payable'"
                >
                    بدهی تأمین‌کنندگان
                </Button>
            </div>
            <section class="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
                <article class="rounded-2xl border bg-card p-4">
                    <p class="text-[10px] text-muted-foreground">جمع کل</p>
                    <p class="mt-2 text-lg font-black">
                        {{ money.format(Number(activeAging.total)) }}
                    </p>
                </article>
                <article
                    v-for="key in bucketKeys"
                    :key="key"
                    class="rounded-2xl border bg-card p-4"
                >
                    <p class="text-[10px] text-muted-foreground">
                        {{ bucketLabels[key] }}
                    </p>
                    <p class="mt-2 text-sm font-black">
                        {{
                            money.format(Number(activeAging.buckets[key] ?? 0))
                        }}
                    </p>
                </article>
            </section>
            <section class="overflow-hidden rounded-2xl border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-right text-sm">
                        <thead
                            class="bg-muted/35 text-[11px] text-muted-foreground"
                        >
                            <tr>
                                <th class="px-5 py-3">طرف‌حساب</th>
                                <th class="px-4 py-3">فاکتور</th>
                                <th class="px-4 py-3">تاریخ</th>
                                <th class="px-4 py-3">سررسید</th>
                                <th class="px-4 py-3">مانده</th>
                                <th class="px-5 py-3">تأخیر</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="row in activeAging.rows"
                                :key="row.invoice_id"
                            >
                                <td class="px-5 py-3 text-xs">
                                    {{ row.party.name }}
                                    <p
                                        class="mt-1 font-mono text-[9px] text-muted-foreground"
                                    >
                                        {{ row.party.code }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs">
                                    {{ row.invoice_number }}
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    {{ formatDate(row.invoice_date) }}
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    {{ formatDate(row.due_date) }}
                                </td>
                                <td class="px-4 py-3 text-xs font-bold">
                                    {{ money.format(Number(row.outstanding)) }}
                                </td>
                                <td class="px-5 py-3 text-xs">
                                    {{ quantity.format(row.days_overdue) }} روز
                                    <p
                                        class="mt-1 text-[9px] text-muted-foreground"
                                    >
                                        {{ bucketLabels[row.bucket] }}
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-if="!activeAging.rows.length"
                    class="py-14 text-center text-sm text-muted-foreground"
                >
                    مانده سررسیدشده‌ای وجود ندارد.
                </p>
            </section>
        </template>

        <template v-else>
            <div
                v-if="!sales.summary.cogs_complete"
                class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-4 text-xs leading-6 text-amber-700 dark:text-amber-300"
            >
                COGS برای {{ sales.summary.incomplete_cogs_count }} ردیف ثبت
                نشده است؛ سود ناخالص و حاشیه سود تا تکمیل snapshot قابل اتکا
                نیست.
            </div>
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <article class="rounded-2xl border bg-card p-4">
                    <p class="text-[10px] text-muted-foreground">فروش خالص</p>
                    <p class="mt-2 text-lg font-black">
                        {{ money.format(Number(sales.summary.revenue)) }}
                    </p>
                </article>
                <article class="rounded-2xl border bg-card p-4">
                    <p class="text-[10px] text-muted-foreground">
                        بهای تمام‌شده
                    </p>
                    <p class="mt-2 text-lg font-black">
                        {{ money.format(Number(sales.summary.cogs)) }}
                    </p>
                </article>
                <article class="rounded-2xl border bg-card p-4">
                    <p class="text-[10px] text-muted-foreground">سود ناخالص</p>
                    <p class="mt-2 text-lg font-black text-emerald-600">
                        {{
                            sales.summary.cogs_complete
                                ? money.format(
                                      Number(sales.summary.gross_profit),
                                  )
                                : 'نامشخص'
                        }}
                    </p>
                </article>
                <article class="rounded-2xl border bg-card p-4">
                    <p class="text-[10px] text-muted-foreground">حاشیه سود</p>
                    <p class="mt-2 text-lg font-black">
                        {{
                            sales.summary.cogs_complete
                                ? `${money.format(Number(sales.summary.gross_margin))}٪`
                                : '—'
                        }}
                    </p>
                </article>
                <article class="rounded-2xl border bg-card p-4">
                    <p class="text-[10px] text-muted-foreground">
                        فاکتور / مرجوعی
                    </p>
                    <p class="mt-2 text-lg font-black">
                        {{ quantity.format(sales.summary.sales_invoices) }} /
                        {{ quantity.format(sales.summary.return_invoices) }}
                    </p>
                </article>
            </section>
            <div class="grid gap-5 xl:grid-cols-2">
                <section class="overflow-hidden rounded-2xl border bg-card">
                    <div class="border-b px-5 py-4">
                        <h2 class="text-sm font-bold">فروش روزانه</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[620px] text-right text-sm">
                            <thead
                                class="bg-muted/35 text-[11px] text-muted-foreground"
                            >
                                <tr>
                                    <th class="px-4 py-3">تاریخ</th>
                                    <th class="px-4 py-3">مقدار</th>
                                    <th class="px-4 py-3">فروش خالص</th>
                                    <th class="px-4 py-3">سود</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="row in sales.daily" :key="row.date">
                                    <td class="px-4 py-3 text-xs">
                                        {{ formatDate(row.date) }}
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        {{
                                            quantity.format(
                                                Number(row.quantity),
                                            )
                                        }}
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        {{ money.format(Number(row.revenue)) }}
                                    </td>
                                    <td class="px-4 py-3 text-xs font-bold">
                                        {{
                                            row.cogs_complete
                                                ? money.format(
                                                      Number(row.gross_profit),
                                                  )
                                                : '—'
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <section class="overflow-hidden rounded-2xl border bg-card">
                    <div class="border-b px-5 py-4">
                        <h2 class="text-sm font-bold">سود کالا</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[720px] text-right text-sm">
                            <thead
                                class="bg-muted/35 text-[11px] text-muted-foreground"
                            >
                                <tr>
                                    <th class="px-4 py-3">کالا</th>
                                    <th class="px-4 py-3">تعداد</th>
                                    <th class="px-4 py-3">فروش</th>
                                    <th class="px-4 py-3">سود</th>
                                    <th class="px-5 py-3">حاشیه</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="row in sales.products"
                                    :key="row.product_id"
                                >
                                    <td class="px-4 py-3 text-xs">
                                        {{ row.name }}
                                        <p
                                            class="mt-1 font-mono text-[9px] text-muted-foreground"
                                        >
                                            {{ row.code }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        {{
                                            quantity.format(
                                                Number(row.quantity),
                                            )
                                        }}
                                        {{ row.unit }}
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        {{ money.format(Number(row.revenue)) }}
                                    </td>
                                    <td class="px-4 py-3 text-xs font-bold">
                                        {{
                                            row.cogs_complete
                                                ? money.format(
                                                      Number(row.gross_profit),
                                                  )
                                                : '—'
                                        }}
                                    </td>
                                    <td class="px-5 py-3 text-xs">
                                        {{
                                            row.cogs_complete
                                                ? `${money.format(Number(row.gross_margin))}٪`
                                                : '—'
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </template>
    </div>
</template>
