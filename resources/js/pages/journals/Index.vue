<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    BookOpen,
    CalendarRange,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    Clock3,
    FilterX,
    PencilLine,
    Plus,
    ScrollText,
    Search,
} from '@lucide/vue';
import { reactive } from 'vue';
import JalaliDatePicker from '@/components/JalaliDatePicker.vue';
import { Button } from '@/components/ui/button';
import {
    create as createJournal,
    edit as editJournal,
    index as journalsIndex,
    show as showJournal,
} from '@/routes/journals';

type EntryKind = 'manual' | 'opening';
type EntryStatus = 'draft' | 'posted' | 'reversed' | 'cancelled';
type EntryRow = {
    id: number;
    entry_number: string;
    entry_kind: EntryKind;
    entry_date: string;
    description: string;
    status: EntryStatus;
    total_debit: string;
    total_credit: string;
    creator: string | null;
    created_at: string | null;
};

const props = defineProps<{
    entries: {
        data: EntryRow[];
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: {
        search: string;
        entry_kind: EntryKind | null;
        status: EntryStatus | null;
        from: string | null;
        to: string | null;
    };
    summary: {
        total: number;
        drafts: number;
        posted: number;
        openings: number;
    };
}>();

const filters = reactive({
    search: props.filters.search,
    entry_kind: props.filters.entry_kind ?? '',
    status: props.filters.status ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
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
    timeZone: 'UTC',
});
const kindMeta: Record<EntryKind, { label: string; className: string }> = {
    manual: {
        label: 'سند دستی',
        className: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
    },
    opening: {
        label: 'مانده افتتاحیه',
        className: 'bg-violet-500/10 text-violet-700 dark:text-violet-300',
    },
};
const statusMeta: Record<
    EntryStatus,
    { label: string; className: string; icon: typeof CircleCheck }
> = {
    draft: {
        label: 'پیش‌نویس',
        className: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
        icon: Clock3,
    },
    posted: {
        label: 'ثبت‌شده',
        className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        icon: CircleCheck,
    },
    reversed: {
        label: 'برگشتی',
        className: 'bg-muted text-muted-foreground',
        icon: ScrollText,
    },
    cancelled: {
        label: 'لغوشده',
        className: 'bg-red-500/10 text-red-700 dark:text-red-300',
        icon: CircleCheck,
    },
};
const formatDate = (value: string) =>
    date.format(new Date(`${value}T12:00:00Z`));
const paginationLabel = (label: string) =>
    /^\d+$/.test(label) ? count.format(Number(label)) : '…';

const applyFilters = () => {
    router.get(
        journalsIndex(),
        {
            search: filters.search.trim() || undefined,
            entry_kind: filters.entry_kind || undefined,
            status: filters.status || undefined,
            from: filters.from || undefined,
            to: filters.to || undefined,
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
    filters.entry_kind = '';
    filters.status = '';
    filters.from = '';
    filters.to = '';
    router.get(
        journalsIndex(),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};
const goToPage = (url: string | null) => {
    if (url) {
        router.visit(url, {
            preserveState: true,
            preserveScroll: true,
        });
    }
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'اسناد دستی',
                href: journalsIndex(),
            },
        ],
    },
});
</script>

<template>
    <Head title="اسناد دستی" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="relative overflow-hidden rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="relative flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <BookOpen class="size-5" />
                        <span class="text-xs font-bold">دفتر اسناد دستی</span>
                    </div>
                    <h1 class="text-2xl font-black tracking-tight sm:text-3xl">
                        اسناد دستی و مانده افتتاحیه
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        سند ابتدا پیش‌نویس می‌شود؛ پس از کنترل توازن، حساب‌ها و
                        دوره باز، فقط از مسیر رسمی ثبت نهایی می‌شود.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button as-child class="h-11 rounded-xl px-5">
                        <Link
                            :href="createJournal({ query: { kind: 'manual' } })"
                        >
                            <Plus class="size-4" />
                            سند دستی
                        </Link>
                    </Button>
                    <Button
                        as-child
                        variant="outline"
                        class="h-11 rounded-xl px-5"
                    >
                        <Link
                            :href="
                                createJournal({ query: { kind: 'opening' } })
                            "
                        >
                            <CalendarRange class="size-4" />
                            مانده افتتاحیه
                        </Link>
                    </Button>
                </div>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article
                class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
            >
                <p class="text-xs text-muted-foreground">کل اسناد دستی</p>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.total) }}
                </p>
            </article>
            <article
                class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
            >
                <p class="text-xs text-muted-foreground">پیش‌نویس‌ها</p>
                <p
                    class="mt-3 text-2xl font-black text-amber-700 dark:text-amber-300"
                >
                    {{ count.format(summary.drafts) }}
                </p>
            </article>
            <article
                class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
            >
                <p class="text-xs text-muted-foreground">ثبت‌شده‌ها</p>
                <p
                    class="mt-3 text-2xl font-black text-emerald-700 dark:text-emerald-300"
                >
                    {{ count.format(summary.posted) }}
                </p>
            </article>
            <article
                class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
            >
                <p class="text-xs text-muted-foreground">مانده‌های افتتاحیه</p>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.openings) }}
                </p>
            </article>
        </section>

        <form
            class="grid gap-3 rounded-2xl border border-border/70 bg-card p-4 shadow-sm md:grid-cols-2 xl:grid-cols-5"
            @submit.prevent="applyFilters"
        >
            <label class="relative">
                <Search
                    class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                />
                <input
                    v-model="filters.search"
                    type="search"
                    placeholder="شماره یا شرح سند..."
                    class="h-10 w-full rounded-xl border border-input bg-background pr-9 pl-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                />
            </label>
            <select
                v-model="filters.entry_kind"
                class="h-10 rounded-xl border border-input bg-background px-3 text-sm"
            >
                <option value="">همه انواع</option>
                <option value="manual">سند دستی</option>
                <option value="opening">مانده افتتاحیه</option>
            </select>
            <select
                v-model="filters.status"
                class="h-10 rounded-xl border border-input bg-background px-3 text-sm"
            >
                <option value="">همه وضعیت‌ها</option>
                <option value="draft">پیش‌نویس</option>
                <option value="posted">ثبت‌شده</option>
            </select>
            <div class="grid grid-cols-2 gap-2">
                <JalaliDatePicker
                    v-model="filters.from"
                    placeholder="از تاریخ"
                />
                <JalaliDatePicker v-model="filters.to" placeholder="تا تاریخ" />
            </div>
            <div class="flex gap-2">
                <Button type="submit" class="h-10 flex-1 rounded-xl">
                    <Search class="size-4" />
                    فیلتر
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

        <section
            class="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
        >
            <div v-if="entries.data.length" class="overflow-x-auto">
                <table class="w-full min-w-[920px] text-right">
                    <thead
                        class="bg-muted/35 text-[11px] text-muted-foreground"
                    >
                        <tr>
                            <th class="px-5 py-3 font-semibold">سند</th>
                            <th class="px-4 py-3 font-semibold">نوع</th>
                            <th class="px-4 py-3 font-semibold">تاریخ</th>
                            <th class="px-4 py-3 font-semibold">مبلغ</th>
                            <th class="px-4 py-3 font-semibold">وضعیت</th>
                            <th class="px-5 py-3 text-left font-semibold">
                                عملیات
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        <tr
                            v-for="entry in entries.data"
                            :key="entry.id"
                            class="transition-colors hover:bg-muted/20"
                        >
                            <td class="px-5 py-4">
                                <Link
                                    :href="showJournal(entry.id)"
                                    class="text-xs font-black text-primary hover:underline"
                                >
                                    {{ entry.entry_number }}
                                </Link>
                                <p
                                    class="mt-1 max-w-72 truncate text-[11px] text-muted-foreground"
                                >
                                    {{ entry.description }}
                                </p>
                            </td>
                            <td class="px-4 py-4">
                                <span
                                    class="rounded-full px-2.5 py-1 text-[10px] font-semibold"
                                    :class="
                                        kindMeta[entry.entry_kind].className
                                    "
                                >
                                    {{ kindMeta[entry.entry_kind].label }}
                                </span>
                            </td>
                            <td class="px-4 py-4 text-xs whitespace-nowrap">
                                {{ formatDate(entry.entry_date) }}
                            </td>
                            <td
                                class="px-4 py-4 text-xs font-bold whitespace-nowrap tabular-nums"
                            >
                                {{ money.format(Number(entry.total_debit)) }}
                                <span
                                    class="text-[9px] font-normal text-muted-foreground"
                                    >ریال</span
                                >
                            </td>
                            <td class="px-4 py-4">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-semibold"
                                    :class="statusMeta[entry.status].className"
                                >
                                    <component
                                        :is="statusMeta[entry.status].icon"
                                        class="size-3"
                                    />
                                    {{ statusMeta[entry.status].label }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-left">
                                <div
                                    class="flex items-center justify-end gap-1"
                                >
                                    <Button
                                        as-child
                                        variant="ghost"
                                        size="sm"
                                        class="rounded-lg"
                                    >
                                        <Link :href="showJournal(entry.id)"
                                            >مشاهده</Link
                                        >
                                    </Button>
                                    <Button
                                        v-if="entry.status === 'draft'"
                                        as-child
                                        variant="ghost"
                                        size="sm"
                                        class="rounded-lg"
                                    >
                                        <Link :href="editJournal(entry.id)">
                                            <PencilLine class="size-4" />
                                            ویرایش
                                        </Link>
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="px-6 py-16 text-center">
                <BookOpen class="mx-auto size-10 text-muted-foreground" />
                <h2 class="mt-4 text-sm font-bold">سندی پیدا نشد</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    اولین سند دستی یا مانده افتتاحیه را ایجاد کنید.
                </p>
            </div>
            <div
                v-if="entries.last_page > 1"
                class="flex flex-col gap-3 border-t border-border/70 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="text-[11px] text-muted-foreground">
                    نمایش {{ count.format(entries.from ?? 0) }} تا
                    {{ count.format(entries.to ?? 0) }} از
                    {{ count.format(entries.total) }}
                </p>
                <div class="flex items-center gap-1">
                    <Button
                        variant="outline"
                        size="icon-sm"
                        :disabled="!entries.links[0]?.url"
                        aria-label="صفحه قبل"
                        @click="goToPage(entries.links[0]?.url ?? null)"
                    >
                        <ChevronRight class="size-4" />
                    </Button>
                    <Button
                        v-for="(link, index) in entries.links.slice(1, -1)"
                        :key="`${link.label}-${index}`"
                        :variant="link.active ? 'default' : 'ghost'"
                        size="icon-sm"
                        class="text-xs"
                        :disabled="!link.url"
                        @click="goToPage(link.url)"
                    >
                        {{ paginationLabel(link.label) }}
                    </Button>
                    <Button
                        variant="outline"
                        size="icon-sm"
                        :disabled="!entries.links.at(-1)?.url"
                        aria-label="صفحه بعد"
                        @click="goToPage(entries.links.at(-1)?.url ?? null)"
                    >
                        <ChevronLeft class="size-4" />
                    </Button>
                </div>
            </div>
        </section>
    </div>
</template>
