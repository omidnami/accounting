<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    CalendarCheck2,
    CalendarClock,
    CalendarDays,
    Check,
    ChevronLeft,
    CircleDot,
    Clock3,
    Landmark,
    Sparkles,
} from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    current as setCurrentFiscalYear,
    index as fiscalYearsIndex,
    store as storeFiscalYear,
} from '@/routes/fiscal-years';

type FiscalYearStatus = 'open' | 'closing' | 'closed';
type PeriodStatus = 'open' | 'soft_closed' | 'closed';
type FiscalYear = {
    id: number;
    title: string;
    calendar: 'jalali' | 'gregorian';
    starts_on: string;
    ends_on: string;
    status: FiscalYearStatus;
    is_current: boolean;
    can_set_current: boolean;
    periods: Array<{
        id: number;
        name: string;
        period_number: number;
        starts_on: string;
        ends_on: string;
        status: PeriodStatus;
    }>;
};

const props = defineProps<{
    years: FiscalYear[];
    summary: {
        years: number;
        current_title: string | null;
        open_periods: number;
        closed_periods: number;
    };
    suggested_year: number;
}>();

const page = usePage();
const errors = computed(() => page.props.errors);
const form = useForm({
    year: props.suggested_year.toString(),
});
const count = new Intl.NumberFormat('fa-IR');
const date = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    timeZone: 'UTC',
});
const yearStatusMeta: Record<
    FiscalYearStatus,
    { label: string; className: string }
> = {
    open: {
        label: 'باز',
        className:
            'border-emerald-500/20 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    },
    closing: {
        label: 'در حال بستن',
        className:
            'border-amber-500/20 bg-amber-500/10 text-amber-700 dark:text-amber-300',
    },
    closed: {
        label: 'بسته',
        className:
            'border-neutral-500/20 bg-neutral-500/10 text-neutral-600 dark:text-neutral-300',
    },
};
const periodStatusMeta: Record<
    PeriodStatus,
    { label: string; className: string; icon: typeof CircleDot }
> = {
    open: {
        label: 'باز',
        className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        icon: CircleDot,
    },
    soft_closed: {
        label: 'نیمه‌بسته',
        className: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
        icon: Clock3,
    },
    closed: {
        label: 'بسته',
        className: 'bg-muted text-muted-foreground',
        icon: Check,
    },
};
const formatDate = (value: string) =>
    date.format(new Date(`${value}T12:00:00Z`));

const createYear = () => {
    form.post(storeFiscalYear().url, {
        preserveScroll: true,
    });
};

const markCurrent = (year: FiscalYear) => {
    if (
        !window.confirm(
            `سال مالی ${year.title} به عنوان سال جاری انتخاب شود؟ فرم‌های مالی و گزارش‌ها از این سال استفاده خواهند کرد.`,
        )
    ) {
        return;
    }

    router.patch(
        setCurrentFiscalYear(year.id),
        {},
        {
            preserveScroll: true,
        },
    );
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'سال‌های مالی',
                href: fiscalYearsIndex(),
            },
        ],
    },
});
</script>

<template>
    <Head title="سال‌های مالی" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="relative overflow-hidden rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="pointer-events-none absolute -top-24 left-8 size-64 rounded-full bg-primary/10 blur-3xl"
            />
            <div
                class="relative flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <CalendarDays class="size-5" />
                        <span class="text-xs font-bold">چرخه سال مالی</span>
                    </div>
                    <h1 class="text-2xl font-black tracking-tight sm:text-3xl">
                        سال‌های مالی و دوره‌های شمسی
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        سال جدید را پیش از پایان سال جاری آماده کنید. هر سال با
                        دوازده دوره و شماره‌گذاری مستقل ایجاد می‌شود.
                    </p>
                </div>
                <div
                    v-if="summary.current_title"
                    class="rounded-2xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-emerald-700 dark:text-emerald-300"
                >
                    <p class="text-[10px] font-semibold">سال مالی جاری</p>
                    <p class="mt-1 text-lg font-black">
                        {{ summary.current_title }}
                    </p>
                </div>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-3">
            <article
                class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <p class="text-xs text-muted-foreground">کل سال‌ها</p>
                    <Landmark class="size-4 text-primary" />
                </div>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.years) }}
                </p>
            </article>
            <article
                class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <p class="text-xs text-muted-foreground">دوره‌های باز</p>
                    <CalendarCheck2 class="size-4 text-emerald-600" />
                </div>
                <p
                    class="mt-3 text-2xl font-black text-emerald-700 dark:text-emerald-300"
                >
                    {{ count.format(summary.open_periods) }}
                </p>
            </article>
            <article
                class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <p class="text-xs text-muted-foreground">
                        دوره‌های بسته یا نیمه‌بسته
                    </p>
                    <CalendarClock class="size-4 text-amber-600" />
                </div>
                <p
                    class="mt-3 text-2xl font-black text-amber-700 dark:text-amber-300"
                >
                    {{ count.format(summary.closed_periods) }}
                </p>
            </article>
        </section>

        <form
            class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
            @submit.prevent="createYear"
        >
            <div
                class="flex flex-col justify-between gap-4 md:flex-row md:items-end"
            >
                <div class="max-w-xl">
                    <h2 class="text-sm font-bold">آماده‌سازی سال مالی</h2>
                    <p class="mt-1 text-xs leading-6 text-muted-foreground">
                        با واردکردن سال شمسی، دوازده دوره ماهانه و شماره‌های سند
                        ایجاد می‌شوند. سال آینده را می‌توانید از قبل بسازید؛ تا
                        زمان انتخاب دستی، سال جاری تغییر نمی‌کند.
                    </p>
                </div>
                <div class="flex w-full max-w-md items-start gap-2">
                    <label class="min-w-0 flex-1">
                        <span
                            class="mb-1.5 block text-xs font-semibold text-muted-foreground"
                            >سال شمسی</span
                        >
                        <input
                            v-model="form.year"
                            type="text"
                            inputmode="numeric"
                            autocomplete="off"
                            class="h-11 w-full rounded-xl border border-input bg-background px-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                            placeholder="مثلاً ۱۴۰۶"
                        />
                        <InputError class="mt-1" :message="form.errors.year" />
                    </label>
                    <Button
                        type="submit"
                        class="mt-[26px] h-11 rounded-xl px-5"
                        :disabled="form.processing"
                    >
                        <Sparkles class="size-4" />
                        ساخت سال
                    </Button>
                </div>
            </div>
            <InputError class="mt-2" :message="errors.fiscal_year" />
        </form>

        <section v-if="years.length" class="space-y-4">
            <article
                v-for="year in years"
                :key="year.id"
                class="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
            >
                <div
                    class="flex flex-col gap-4 px-5 py-5 lg:flex-row lg:items-center lg:justify-between"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-primary"
                        >
                            <CalendarDays class="size-5" />
                        </span>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-base font-black">
                                    سال مالی {{ year.title }}
                                </h2>
                                <span
                                    v-if="year.is_current"
                                    class="rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-1 text-[10px] font-bold text-emerald-700 dark:text-emerald-300"
                                >
                                    سال جاری
                                </span>
                                <span
                                    class="rounded-full border px-2.5 py-1 text-[10px] font-semibold"
                                    :class="
                                        yearStatusMeta[year.status].className
                                    "
                                >
                                    {{ yearStatusMeta[year.status].label }}
                                </span>
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ formatDate(year.starts_on) }} تا
                                {{ formatDate(year.ends_on) }} ·
                                {{ count.format(year.periods.length) }} دوره
                            </p>
                        </div>
                    </div>
                    <Button
                        v-if="year.can_set_current"
                        type="button"
                        variant="outline"
                        class="w-fit rounded-xl"
                        @click="markCurrent(year)"
                    >
                        <Check class="size-4" />
                        تعیین به‌عنوان سال جاری
                    </Button>
                </div>

                <details
                    class="border-t border-border/70"
                    :open="year.is_current"
                >
                    <summary
                        class="flex cursor-pointer list-none items-center justify-between px-5 py-3 text-xs font-semibold text-muted-foreground transition-colors hover:bg-muted/25"
                    >
                        مشاهده دوره‌های ماهانه
                        <ChevronLeft class="size-4" />
                    </summary>
                    <div class="overflow-x-auto border-t border-border/60">
                        <table class="w-full min-w-[680px] text-right">
                            <thead
                                class="bg-muted/35 text-[11px] text-muted-foreground"
                            >
                                <tr>
                                    <th class="px-5 py-3 font-semibold">
                                        دوره
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        از تاریخ
                                    </th>
                                    <th class="px-4 py-3 font-semibold">
                                        تا تاریخ
                                    </th>
                                    <th class="px-5 py-3 font-semibold">
                                        وضعیت
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/60">
                                <tr
                                    v-for="period in year.periods"
                                    :key="period.id"
                                >
                                    <td class="px-5 py-3 text-xs font-bold">
                                        {{ period.name }}
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        {{ formatDate(period.starts_on) }}
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        {{ formatDate(period.ends_on) }}
                                    </td>
                                    <td class="px-5 py-3">
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-semibold"
                                            :class="
                                                periodStatusMeta[period.status]
                                                    .className
                                            "
                                        >
                                            <component
                                                :is="
                                                    periodStatusMeta[
                                                        period.status
                                                    ].icon
                                                "
                                                class="size-3"
                                            />
                                            {{
                                                periodStatusMeta[period.status]
                                                    .label
                                            }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </details>
            </article>
        </section>

        <section
            v-else
            class="rounded-2xl border border-dashed border-border bg-card px-6 py-16 text-center"
        >
            <CalendarDays class="mx-auto size-10 text-muted-foreground" />
            <h2 class="mt-4 text-sm font-bold">سال مالی ثبت نشده است</h2>
            <p class="mt-1 text-xs text-muted-foreground">
                اولین سال مالی را از فرم بالا بسازید.
            </p>
        </section>
    </div>
</template>
