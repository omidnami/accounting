<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    BookOpen,
    CalendarDays,
    CheckCircle2,
    CircleAlert,
    PencilLine,
    Trash2,
    UserRound,
} from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    destroy as destroyJournal,
    edit as editJournal,
    index as journalsIndex,
    post as postJournal,
} from '@/routes/journals';

type Line = {
    id: number;
    account: { code: string; name: string };
    party: { code: string; name: string } | null;
    debit: string;
    credit: string;
    description: string | null;
};
type Entry = {
    id: number;
    entry_number: string;
    entry_kind: 'manual' | 'opening';
    entry_date: string;
    description: string;
    status: 'draft' | 'posted' | 'reversed' | 'cancelled';
    total_debit: string;
    total_credit: string;
    creator: string | null;
    created_at: string | null;
    can_edit: boolean;
    can_post: boolean;
    branch: { name: string };
    fiscal_year: { title: string };
    accounting_period: { name: string };
    lines: Line[];
};

const props = defineProps<{ entry: Entry }>();
const page = usePage();
const errors = computed(() => page.props.errors);
const money = new Intl.NumberFormat('fa-IR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});
const date = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    timeZone: 'UTC',
});
const formatDate = (value: string) =>
    date.format(new Date(`${value}T12:00:00Z`));
const kindLabel = computed(() =>
    props.entry.entry_kind === 'opening' ? 'مانده افتتاحیه' : 'سند دستی',
);
const statusMeta = computed(() => {
    if (props.entry.status === 'draft') {
        return {
            label: 'پیش‌نویس',
            className:
                'border-amber-500/20 bg-amber-500/10 text-amber-700 dark:text-amber-300',
        };
    }

    if (props.entry.status === 'posted') {
        return {
            label: 'ثبت نهایی‌شده',
            className:
                'border-emerald-500/20 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        };
    }

    return {
        label: props.entry.status === 'cancelled' ? 'لغوشده' : 'برگشتی',
        className: 'border-border bg-muted text-muted-foreground',
    };
});

const postEntry = () => {
    if (
        !window.confirm(
            'سند پیش‌نویس به‌صورت نهایی ثبت شود؟ پس از ثبت، ویرایش یا حذف مستقیم مجاز نیست.',
        )
    ) {
        return;
    }

    router.post(postJournal(props.entry.id), {}, { preserveScroll: true });
};
const deleteEntry = () => {
    if (!window.confirm('پیش‌نویس سند حذف شود؟')) {
        return;
    }

    router.delete(destroyJournal(props.entry.id));
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'اسناد دستی', href: journalsIndex() },
            { title: 'جزئیات سند', href: '#' },
        ],
    },
});
</script>

<template>
    <Head :title="`سند ${entry.entry_number}`" />

    <div class="mx-auto w-full max-w-6xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.08)_100%)] p-6 sm:p-8"
        >
            <div
                class="flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <BookOpen class="size-5" />
                        <span class="text-xs font-bold">{{ kindLabel }}</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1
                            class="font-mono text-2xl font-black tracking-tight sm:text-3xl"
                        >
                            {{ entry.entry_number }}
                        </h1>
                        <span
                            class="rounded-full border px-3 py-1 text-[10px] font-bold"
                            :class="statusMeta.className"
                        >
                            {{ statusMeta.label }}
                        </span>
                    </div>
                    <p
                        class="mt-3 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        {{ entry.description }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="entry.can_post"
                        type="button"
                        class="rounded-xl"
                        @click="postEntry"
                    >
                        <CheckCircle2 class="size-4" />
                        ثبت نهایی
                    </Button>
                    <Button
                        v-if="entry.can_edit"
                        as-child
                        variant="outline"
                        class="rounded-xl"
                    >
                        <Link :href="editJournal(entry.id)">
                            <PencilLine class="size-4" />
                            ویرایش
                        </Link>
                    </Button>
                    <Button
                        v-if="entry.can_edit"
                        type="button"
                        variant="ghost"
                        class="rounded-xl text-red-600"
                        @click="deleteEntry"
                    >
                        <Trash2 class="size-4" />
                        حذف
                    </Button>
                </div>
            </div>
        </section>

        <InputError :message="errors.journal" />

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-2xl border border-border/70 bg-card p-4">
                <p
                    class="flex items-center gap-1.5 text-[10px] text-muted-foreground"
                >
                    <CalendarDays class="size-3.5" /> تاریخ سند
                </p>
                <p class="mt-2 text-sm font-bold">
                    {{ formatDate(entry.entry_date) }}
                </p>
            </article>
            <article class="rounded-2xl border border-border/70 bg-card p-4">
                <p class="text-[10px] text-muted-foreground">سال و دوره</p>
                <p class="mt-2 text-sm font-bold">
                    {{ entry.fiscal_year.title }} ·
                    {{ entry.accounting_period.name }}
                </p>
            </article>
            <article class="rounded-2xl border border-border/70 bg-card p-4">
                <p class="text-[10px] text-muted-foreground">
                    شعبه و ایجادکننده
                </p>
                <p class="mt-2 text-sm font-bold">{{ entry.branch.name }}</p>
                <p
                    class="mt-1 flex items-center gap-1 text-[10px] text-muted-foreground"
                >
                    <UserRound class="size-3" />
                    {{ entry.creator || 'سیستم' }}
                </p>
            </article>
            <article
                class="rounded-2xl border border-primary/20 bg-primary/5 p-4"
            >
                <p class="text-[10px] text-muted-foreground">مبلغ سند</p>
                <p class="mt-2 text-lg font-black text-primary tabular-nums">
                    {{ money.format(Number(entry.total_debit)) }}
                    <span class="text-[9px] font-medium">ریال</span>
                </p>
            </article>
        </section>

        <section
            class="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
        >
            <div class="border-b border-border/70 px-5 py-4">
                <h2 class="text-sm font-bold">ردیف‌های سند</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-right text-sm">
                    <thead
                        class="bg-muted/35 text-[11px] text-muted-foreground"
                    >
                        <tr>
                            <th class="px-5 py-3">حساب</th>
                            <th class="px-4 py-3">طرف‌حساب</th>
                            <th class="px-4 py-3">شرح</th>
                            <th class="px-4 py-3">بدهکار</th>
                            <th class="px-5 py-3">بستانکار</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        <tr v-for="line in entry.lines" :key="line.id">
                            <td class="px-5 py-4">
                                <p class="text-xs font-bold">
                                    {{ line.account.name }}
                                </p>
                                <p
                                    class="mt-1 font-mono text-[10px] text-muted-foreground"
                                >
                                    {{ line.account.code }}
                                </p>
                            </td>
                            <td class="px-4 py-4 text-xs">
                                <template v-if="line.party">
                                    {{ line.party.name }}
                                    <span
                                        class="mt-1 block font-mono text-[9px] text-muted-foreground"
                                    >
                                        {{ line.party.code }}
                                    </span>
                                </template>
                                <span v-else>—</span>
                            </td>
                            <td class="px-4 py-4 text-xs text-muted-foreground">
                                {{ line.description || '—' }}
                            </td>
                            <td
                                class="px-4 py-4 text-xs font-semibold tabular-nums"
                            >
                                {{
                                    Number(line.debit) > 0
                                        ? money.format(Number(line.debit))
                                        : '—'
                                }}
                            </td>
                            <td
                                class="px-5 py-4 text-xs font-semibold tabular-nums"
                            >
                                {{
                                    Number(line.credit) > 0
                                        ? money.format(Number(line.credit))
                                        : '—'
                                }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t bg-muted/25 text-xs font-black">
                        <tr>
                            <td colspan="3" class="px-5 py-4">جمع سند</td>
                            <td class="px-4 py-4 tabular-nums">
                                {{ money.format(Number(entry.total_debit)) }}
                            </td>
                            <td class="px-5 py-4 tabular-nums">
                                {{ money.format(Number(entry.total_credit)) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <div
            v-if="entry.status === 'posted'"
            class="flex items-start gap-3 rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-4 text-emerald-700 dark:text-emerald-300"
        >
            <CheckCircle2 class="mt-0.5 size-5 shrink-0" />
            <p class="text-xs leading-6">
                این سند به‌صورت نهایی ثبت شده و در گزارش‌های مالی لحاظ می‌شود.
                اصلاح آن باید با سند برگشتی یا اصلاحی انجام شود.
            </p>
        </div>
        <div
            v-else
            class="flex items-start gap-3 rounded-2xl border border-amber-500/20 bg-amber-500/5 p-4 text-amber-700 dark:text-amber-300"
        >
            <CircleAlert class="mt-0.5 size-5 shrink-0" />
            <p class="text-xs leading-6">
                این سند پیش‌نویس است و هیچ اثر مالی یا گزارش رسمی ندارد.
            </p>
        </div>

        <Button as-child variant="outline" class="rounded-xl">
            <Link :href="journalsIndex()">
                <ArrowRight class="size-4" />
                بازگشت به فهرست
            </Link>
        </Button>
    </div>
</template>
