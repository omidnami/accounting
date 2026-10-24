<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BarChart3,
    BookOpen,
    BookText,
    Building2,
    Landmark,
    ReceiptText,
    Scale,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import JalaliDatePicker from '@/components/JalaliDatePicker.vue';
import { index as reports } from '@/routes/reports';

type ReportRow = {
    code: string;
    name: string;
    amount: string;
};

type TrialRow = {
    account_id: number;
    code: string;
    name: string;
    type: string;
    level: string;
    opening_debit: string;
    opening_credit: string;
    debit: string;
    credit: string;
    closing_debit: string;
    closing_credit: string;
};

type Props = {
    filters: {
        from: string;
        to: string;
        branch_id: number | null;
        account_id: number | null;
    };
    accounts: Array<{
        id: number;
        code: string;
        name: string;
        is_active: boolean;
    }>;
    branches: Array<{ id: number; name: string; is_active: boolean }>;
    trialBalance: {
        rows: TrialRow[];
        total_opening_debit: string;
        total_opening_credit: string;
        total_debit: string;
        total_credit: string;
        total_closing_debit: string;
        total_closing_credit: string;
        balanced: boolean;
    };
    incomeStatement: {
        revenues: ReportRow[];
        expenses: ReportRow[];
        total_revenue: string;
        total_expense: string;
        net_profit: string;
    };
    balanceSheet: {
        assets: ReportRow[];
        liabilities: ReportRow[];
        equity: ReportRow[];
        total_assets: string;
        total_liabilities: string;
        total_equity: string;
        net_profit: string;
        balanced: boolean;
    };
    accountLedger: {
        account: { id: number; code: string; name: string };
        opening_balance: string;
        debit_total: string;
        credit_total: string;
        closing_balance: string;
        rows: Array<{
            entry_id: number;
            entry_number: string;
            account_code: string;
            account_name: string;
            date: string;
            description: string;
            debit: string;
            credit: string;
            balance: string;
        }>;
    } | null;
    journal: {
        data: Array<{
            id: number;
            entry_number: string;
            entry_date: string;
            description: string;
            lines: Array<{
                id: number;
                debit: string;
                credit: string;
                description: string | null;
                account: { code: string; name: string };
            }>;
        }>;
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
};

const props = defineProps<Props>();
const tabs = [
    { key: 'trial', label: 'تراز آزمایشی', icon: Scale },
    { key: 'income', label: 'سود و زیان', icon: BarChart3 },
    { key: 'balance', label: 'ترازنامه', icon: Landmark },
    { key: 'journal', label: 'دفتر روزنامه', icon: BookOpen },
    { key: 'ledger', label: 'دفتر معین', icon: BookText },
] as const;
const activeTab = ref<(typeof tabs)[number]['key']>('trial');
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const branchId = ref<string>(props.filters.branch_id?.toString() ?? '');
const accountId = ref<string>(props.filters.account_id?.toString() ?? '');
const money = new Intl.NumberFormat('fa-IR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});
const typeLabel = computed<Record<string, string>>(() => ({
    asset: 'دارایی',
    liability: 'بدهی',
    equity: 'سرمایه',
    revenue: 'درآمد',
    expense: 'هزینه',
}));
const format = (value: string | null) =>
    value ? money.format(Number(value)) : '—';

const applyFilters = () => {
    router.get(
        reports(),
        {
            from: from.value,
            to: to.value,
            branch_id: branchId.value || undefined,
            account_id: accountId.value || undefined,
        },
        {
            preserveState: true,
            replace: true,
        },
    );
};

const changeJournalPage = (page: number) => {
    router.get(
        reports(),
        {
            from: props.filters.from,
            to: props.filters.to,
            branch_id: props.filters.branch_id ?? undefined,
            page,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'گزارش‌های مالی',
                href: reports(),
            },
        ],
    },
});
</script>

<template>
    <Head title="گزارش‌های مالی" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="relative overflow-hidden rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.08)_100%)] p-6 sm:p-8"
        >
            <div
                class="relative flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <BarChart3 class="size-5" />
                        <span class="text-xs font-bold"
                            >مرکز گزارش‌های مالی</span
                        >
                    </div>
                    <h1 class="text-2xl font-black tracking-tight sm:text-3xl">
                        تصویر روشن از حساب‌های فروشگاه
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        گزارش‌ها فقط بر اساس اسناد ثبت‌شده محاسبه می‌شوند.
                    </p>
                </div>
                <span
                    class="inline-flex w-fit items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold"
                    :class="
                        trialBalance.balanced
                            ? 'border-primary/20 bg-primary/10 text-primary'
                            : 'border-red-200 bg-red-50 text-red-700'
                    "
                >
                    <span
                        class="size-2 rounded-full"
                        :class="
                            trialBalance.balanced ? 'bg-primary' : 'bg-red-500'
                        "
                    />
                    {{
                        trialBalance.balanced
                            ? 'دفاتر متوازن است'
                            : 'عدم توازن دفاتر'
                    }}
                </span>
            </div>
        </section>

        <form
            class="grid gap-3 rounded-2xl border border-border/70 bg-card p-4 shadow-sm sm:grid-cols-2 xl:grid-cols-[1fr_1fr_1.2fr_1.5fr_auto]"
            @submit.prevent="applyFilters"
        >
            <label class="space-y-1.5">
                <span class="text-xs font-semibold text-muted-foreground"
                    >از تاریخ</span
                >
                <JalaliDatePicker v-model="from" required />
            </label>
            <label class="space-y-1.5">
                <span class="text-xs font-semibold text-muted-foreground"
                    >حساب دفتر معین</span
                >
                <select
                    v-model="accountId"
                    class="h-10 w-full rounded-xl border border-input bg-background px-3 text-sm"
                >
                    <option
                        v-for="account in accounts"
                        :key="account.id"
                        :value="account.id.toString()"
                    >
                        {{ account.code }} — {{ account.name }}
                        {{ account.is_active ? '' : '(غیرفعال)' }}
                    </option>
                </select>
            </label>
            <label class="space-y-1.5">
                <span class="text-xs font-semibold text-muted-foreground"
                    >تا تاریخ</span
                >
                <JalaliDatePicker v-model="to" required />
            </label>
            <label class="space-y-1.5">
                <span class="text-xs font-semibold text-muted-foreground"
                    >شعبه</span
                >
                <span class="relative block">
                    <Building2
                        class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                    />
                    <select
                        v-model="branchId"
                        class="h-10 w-full appearance-none rounded-xl border border-input bg-background py-2 pr-9 pl-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                    >
                        <option value="">همه شعبه‌ها</option>
                        <option
                            v-for="branch in branches"
                            :key="branch.id"
                            :value="branch.id.toString()"
                        >
                            {{ branch.name }}
                            {{ branch.is_active ? '' : '(غیرفعال)' }}
                        </option>
                    </select>
                </span>
            </label>
            <Button type="submit" class="mt-auto h-10 rounded-xl px-5">
                اعمال فیلتر
                <ArrowLeft class="size-4" />
            </Button>
        </form>

        <div
            class="flex gap-2 overflow-x-auto rounded-2xl border border-border/70 bg-card p-2 shadow-sm"
        >
            <button
                v-for="tab in tabs"
                :key="tab.key"
                type="button"
                class="flex shrink-0 items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition-colors"
                :class="
                    activeTab === tab.key
                        ? 'bg-primary text-primary-foreground shadow-sm'
                        : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                "
                @click="activeTab = tab.key"
            >
                <component :is="tab.icon" class="size-4" />
                {{ tab.label }}
            </button>
        </div>

        <template v-if="activeTab === 'trial'">
            <section class="grid gap-4 sm:grid-cols-3">
                <article
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <p class="text-xs text-muted-foreground">جمع گردش بدهکار</p>
                    <p class="mt-2 text-xl font-black">
                        {{ format(trialBalance.total_debit) }}
                    </p>
                </article>
                <article
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <p class="text-xs text-muted-foreground">
                        جمع گردش بستانکار
                    </p>
                    <p class="mt-2 text-xl font-black">
                        {{ format(trialBalance.total_credit) }}
                    </p>
                </article>
                <article
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <p class="text-xs text-muted-foreground">
                        مانده پایان دوره
                    </p>
                    <p class="mt-2 text-xl font-black">
                        {{ format(trialBalance.total_closing_debit) }}
                    </p>
                </article>
            </section>

            <section
                class="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
            >
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-right text-sm">
                        <thead
                            class="border-b bg-muted/45 text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-3">کد</th>
                                <th class="px-4 py-3">حساب</th>
                                <th class="px-4 py-3">نوع</th>
                                <th class="px-4 py-3">گردش بدهکار</th>
                                <th class="px-4 py-3">گردش بستانکار</th>
                                <th class="px-4 py-3">مانده بدهکار</th>
                                <th class="px-4 py-3">مانده بستانکار</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/60">
                            <tr
                                v-for="row in trialBalance.rows"
                                :key="row.account_id"
                                class="hover:bg-muted/25"
                                :class="
                                    row.level === 'general'
                                        ? 'bg-muted/20 font-bold'
                                        : ''
                                "
                            >
                                <td class="px-4 py-3 font-mono text-xs">
                                    {{ row.code }}
                                </td>
                                <td
                                    class="px-4 py-3"
                                    :class="
                                        row.level === 'subsidiary' ? 'pr-8' : ''
                                    "
                                >
                                    {{ row.name }}
                                </td>
                                <td
                                    class="px-4 py-3 text-xs text-muted-foreground"
                                >
                                    {{ typeLabel[row.type] }}
                                </td>
                                <td class="px-4 py-3 tabular-nums">
                                    {{ format(row.debit) }}
                                </td>
                                <td class="px-4 py-3 tabular-nums">
                                    {{ format(row.credit) }}
                                </td>
                                <td class="px-4 py-3 tabular-nums">
                                    {{ format(row.closing_debit) }}
                                </td>
                                <td class="px-4 py-3 tabular-nums">
                                    {{ format(row.closing_credit) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>

        <template v-else-if="activeTab === 'income'">
            <section class="grid gap-4 md:grid-cols-3">
                <article
                    class="rounded-2xl border border-primary/15 bg-primary/8 p-5"
                >
                    <p class="text-xs text-primary">جمع درآمد</p>
                    <p class="mt-2 text-2xl font-black text-primary">
                        {{ format(incomeStatement.total_revenue) }}
                    </p>
                </article>
                <article
                    class="rounded-2xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-400/15 dark:bg-amber-400/8"
                >
                    <p class="text-xs text-amber-700 dark:text-amber-400">
                        جمع هزینه
                    </p>
                    <p
                        class="mt-2 text-2xl font-black text-amber-700 dark:text-amber-400"
                    >
                        {{ format(incomeStatement.total_expense) }}
                    </p>
                </article>
                <article
                    class="rounded-2xl border border-border/70 bg-card p-5"
                >
                    <p class="text-xs text-muted-foreground">سود خالص دوره</p>
                    <p class="mt-2 text-2xl font-black">
                        {{ format(incomeStatement.net_profit) }}
                    </p>
                </article>
            </section>

            <section class="grid gap-4 lg:grid-cols-2">
                <article
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <h2 class="mb-4 text-sm font-bold text-primary">درآمدها</h2>
                    <div class="space-y-3">
                        <div
                            v-for="row in incomeStatement.revenues"
                            :key="row.code"
                            class="flex items-center justify-between gap-4 border-b border-dashed pb-3 text-sm"
                        >
                            <span>{{ row.name }}</span>
                            <span class="font-bold tabular-nums">{{
                                format(row.amount)
                            }}</span>
                        </div>
                        <p
                            v-if="incomeStatement.revenues.length === 0"
                            class="py-8 text-center text-sm text-muted-foreground"
                        >
                            درآمدی ثبت نشده است.
                        </p>
                    </div>
                </article>
                <article
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <h2 class="mb-4 text-sm font-bold text-amber-600">
                        هزینه‌ها
                    </h2>
                    <div class="space-y-3">
                        <div
                            v-for="row in incomeStatement.expenses"
                            :key="row.code"
                            class="flex items-center justify-between gap-4 border-b border-dashed pb-3 text-sm"
                        >
                            <span>{{ row.name }}</span>
                            <span class="font-bold tabular-nums">{{
                                format(row.amount)
                            }}</span>
                        </div>
                        <p
                            v-if="incomeStatement.expenses.length === 0"
                            class="py-8 text-center text-sm text-muted-foreground"
                        >
                            هزینه‌ای ثبت نشده است.
                        </p>
                    </div>
                </article>
            </section>
        </template>

        <template v-else-if="activeTab === 'balance'">
            <section class="grid gap-4 lg:grid-cols-2">
                <article
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <div class="mb-5 flex items-center justify-between">
                        <h2 class="font-bold">دارایی‌ها</h2>
                        <span class="text-lg font-black text-primary">{{
                            format(balanceSheet.total_assets)
                        }}</span>
                    </div>
                    <div class="space-y-3">
                        <div
                            v-for="row in balanceSheet.assets"
                            :key="row.code"
                            class="flex items-center justify-between gap-4 border-b border-dashed pb-3 text-sm"
                        >
                            <span>{{ row.name }}</span>
                            <span class="font-bold tabular-nums">{{
                                format(row.amount)
                            }}</span>
                        </div>
                    </div>
                </article>
                <article
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <div class="mb-5 flex items-center justify-between">
                        <h2 class="font-bold">بدهی‌ها و حقوق صاحبان سهام</h2>
                        <span class="text-lg font-black text-primary">{{
                            format(balanceSheet.total_liabilities)
                        }}</span>
                    </div>
                    <p class="mb-3 text-xs font-semibold text-muted-foreground">
                        بدهی‌ها
                    </p>
                    <div class="mb-5 space-y-3">
                        <div
                            v-for="row in balanceSheet.liabilities"
                            :key="row.code"
                            class="flex items-center justify-between gap-4 border-b border-dashed pb-3 text-sm"
                        >
                            <span>{{ row.name }}</span>
                            <span class="font-bold tabular-nums">{{
                                format(row.amount)
                            }}</span>
                        </div>
                    </div>
                    <p class="mb-3 text-xs font-semibold text-muted-foreground">
                        سرمایه
                    </p>
                    <div class="space-y-3">
                        <div
                            v-for="row in balanceSheet.equity"
                            :key="row.code"
                            class="flex items-center justify-between gap-4 border-b border-dashed pb-3 text-sm"
                        >
                            <span>{{ row.name }}</span>
                            <span class="font-bold tabular-nums">{{
                                format(row.amount)
                            }}</span>
                        </div>
                        <div
                            class="flex items-center justify-between gap-4 rounded-xl bg-primary/8 p-3 text-sm"
                        >
                            <span class="font-semibold">سود دوره جاری</span>
                            <span class="font-black text-primary">{{
                                format(balanceSheet.net_profit)
                            }}</span>
                        </div>
                    </div>
                </article>
            </section>
            <p
                class="text-center text-xs"
                :class="balanceSheet.balanced ? 'text-primary' : 'text-red-600'"
            >
                {{
                    balanceSheet.balanced
                        ? 'دارایی‌ها با بدهی‌ها و حقوق صاحبان سهام برابر است.'
                        : 'ترازنامه متوازن نیست.'
                }}
            </p>
        </template>

        <template v-else-if="activeTab === 'journal'">
            <section class="space-y-4">
                <article
                    v-for="entry in journal.data"
                    :key="entry.id"
                    class="rounded-2xl border border-border/70 bg-card shadow-sm"
                >
                    <header
                        class="flex flex-col justify-between gap-3 border-b bg-muted/25 px-5 py-4 sm:flex-row sm:items-center"
                    >
                        <div>
                            <div class="flex items-center gap-2">
                                <ReceiptText class="size-4 text-primary" />
                                <span
                                    class="font-mono text-xs font-bold text-primary"
                                    >{{ entry.entry_number }}</span
                                >
                                <span class="text-xs text-muted-foreground">{{
                                    entry.entry_date
                                }}</span>
                            </div>
                            <p class="mt-1 text-sm font-semibold">
                                {{ entry.description }}
                            </p>
                        </div>
                    </header>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[600px] text-right text-sm">
                            <thead class="text-xs text-muted-foreground">
                                <tr>
                                    <th class="px-5 py-2">حساب</th>
                                    <th class="px-5 py-2">شرح</th>
                                    <th class="px-5 py-2">بدهکار</th>
                                    <th class="px-5 py-2">بستانکار</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/50">
                                <tr v-for="line in entry.lines" :key="line.id">
                                    <td class="px-5 py-2.5">
                                        <span class="font-mono text-xs">{{
                                            line.account.code
                                        }}</span>
                                        {{ line.account.name }}
                                    </td>
                                    <td
                                        class="px-5 py-2.5 text-xs text-muted-foreground"
                                    >
                                        {{ line.description || '—' }}
                                    </td>
                                    <td class="px-5 py-2.5 tabular-nums">
                                        {{ format(line.debit) }}
                                    </td>
                                    <td class="px-5 py-2.5 tabular-nums">
                                        {{ format(line.credit) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>
                <p
                    v-if="journal.data.length === 0"
                    class="rounded-2xl border border-dashed bg-card py-16 text-center text-sm text-muted-foreground"
                >
                    در این بازه سندی ثبت نشده است.
                </p>
                <div
                    v-if="journal.last_page > 1"
                    class="flex items-center justify-between rounded-xl border bg-card px-4 py-3 text-sm"
                >
                    <span class="text-muted-foreground"
                        >{{ journal.from ?? 0 }} تا {{ journal.to ?? 0 }} از
                        {{ journal.total }}</span
                    >
                    <div class="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="!journal.prev_page_url"
                            @click="changeJournalPage(journal.current_page - 1)"
                            >قبلی</Button
                        >
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="!journal.next_page_url"
                            @click="changeJournalPage(journal.current_page + 1)"
                            >بعدی</Button
                        >
                    </div>
                </div>
            </section>
        </template>

        <template v-else>
            <section v-if="accountLedger" class="grid gap-4 sm:grid-cols-4">
                <article class="rounded-2xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">مانده اول دوره</p>
                    <p class="mt-2 text-lg font-black">
                        {{ format(accountLedger.opening_balance) }}
                    </p>
                </article>
                <article class="rounded-2xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">گردش بدهکار</p>
                    <p class="mt-2 text-lg font-black">
                        {{ format(accountLedger.debit_total) }}
                    </p>
                </article>
                <article class="rounded-2xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">گردش بستانکار</p>
                    <p class="mt-2 text-lg font-black">
                        {{ format(accountLedger.credit_total) }}
                    </p>
                </article>
                <article
                    class="rounded-2xl border border-primary/20 bg-primary/5 p-4"
                >
                    <p class="text-xs text-muted-foreground">
                        مانده پایان دوره
                    </p>
                    <p class="mt-2 text-lg font-black text-primary">
                        {{ format(accountLedger.closing_balance) }}
                    </p>
                </article>
            </section>
            <section
                v-if="accountLedger"
                class="overflow-hidden rounded-2xl border bg-card"
            >
                <div class="border-b px-5 py-4">
                    <h2 class="text-sm font-bold">
                        {{ accountLedger.account.code }} —
                        {{ accountLedger.account.name }}
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-right text-sm">
                        <thead
                            class="bg-muted/35 text-xs text-muted-foreground"
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
                                v-for="row in accountLedger.rows"
                                :key="`${row.entry_id}-${row.date}-${row.balance}`"
                            >
                                <td class="px-4 py-3 text-xs">
                                    {{ row.date }}
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
                                    {{ format(row.debit) }}
                                </td>
                                <td class="px-4 py-3 text-xs tabular-nums">
                                    {{ format(row.credit) }}
                                </td>
                                <td
                                    class="px-5 py-3 text-xs font-bold tabular-nums"
                                >
                                    {{ format(row.balance) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-if="accountLedger.rows.length === 0"
                    class="py-14 text-center text-sm text-muted-foreground"
                >
                    گردشی برای این حساب در بازه انتخاب‌شده وجود ندارد.
                </p>
            </section>
        </template>
    </div>
</template>
