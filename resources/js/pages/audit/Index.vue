<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    Clock3,
    Download,
    FilterX,
    History,
    Search,
    ShieldAlert,
    UserCog,
} from '@lucide/vue';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import {
    exportMethod as exportAuditLogs,
    index as auditLogsIndex,
} from '@/routes/audit-logs';

type Event = 'created' | 'updated' | 'deleted';
type AuditRow = {
    id: number;
    event: Event;
    entity: string;
    entity_id: number;
    description: string;
    actor: { name: string; email: string } | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    ip_address: string | null;
    created_at: string | null;
};

const props = defineProps<{
    logs: {
        data: AuditRow[];
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: {
        search: string;
        event: Event | null;
        entity: string | null;
        date: 'today' | '7_days' | null;
    };
    model_options: string[];
    summary: {
        total: number;
        today: number;
        security: number;
        financial: number;
    };
}>();

const filters = reactive({
    search: props.filters.search,
    event: props.filters.event ?? '',
    entity: props.filters.entity ?? '',
    date: props.filters.date ?? '',
});
const count = new Intl.NumberFormat('fa-IR');
const dateTime = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});
const eventMeta: Record<Event, { label: string; className: string }> = {
    created: {
        label: 'ایجاد',
        className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    },
    updated: {
        label: 'ویرایش',
        className: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    },
    deleted: {
        label: 'حذف',
        className: 'bg-red-500/10 text-red-700 dark:text-red-300',
    },
};
const pageLabel = (label: string) =>
    /^\d+$/.test(label) ? count.format(Number(label)) : '…';
const valuesText = (values: Record<string, unknown> | null) =>
    values ? JSON.stringify(values, null, 2) : '—';
const applyFilters = () => {
    router.get(
        auditLogsIndex(),
        {
            search: filters.search.trim() || undefined,
            event: filters.event || undefined,
            entity: filters.entity || undefined,
            date: filters.date || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
const resetFilters = () => {
    filters.search = '';
    filters.event = '';
    filters.entity = '';
    filters.date = '';
    router.get(auditLogsIndex(), {}, { preserveScroll: true });
};
const exportHref = () =>
    exportAuditLogs({
        query: {
            search: props.filters.search || undefined,
            event: props.filters.event ?? undefined,
            entity: props.filters.entity ?? undefined,
            date: props.filters.date ?? undefined,
        },
    }).url;

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'گزارش رویدادها', href: auditLogsIndex() }],
    },
});
</script>

<template>
    <Head title="Audit Log" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div class="mb-3 flex items-center gap-2 text-primary">
                <History class="size-5" />
                <span class="text-xs font-bold">ردپای تغییرات</span>
            </div>
            <h1 class="text-2xl font-black sm:text-3xl">
                Audit Log عملیات مالی و امنیتی
            </h1>
            <p class="mt-2 max-w-3xl text-sm leading-7 text-muted-foreground">
                ایجاد، ویرایش و حذف مدل‌های حساس همراه با کاربر، زمان، IP و
                تغییرات قبل/بعد ثبت می‌شود.
            </p>
            <div class="mt-5">
                <Button as-child variant="outline" class="rounded-xl">
                    <a :href="exportHref()">
                        <Download class="size-4" />
                        خروجی CSV (Excel)
                    </a>
                </Button>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-2xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">کل رویدادها</p>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.total) }}
                </p>
            </article>
            <article class="rounded-2xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">امروز</p>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.today) }}
                </p>
            </article>
            <article class="rounded-2xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">رویدادهای کاربران</p>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.security) }}
                </p>
            </article>
            <article class="rounded-2xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">رویدادهای مالی</p>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.financial) }}
                </p>
            </article>
        </section>

        <form
            class="grid gap-3 rounded-2xl border bg-card p-4 lg:grid-cols-[minmax(0,1fr)_150px_170px_150px_auto]"
            @submit.prevent="applyFilters"
        >
            <label class="relative">
                <Search
                    class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                />
                <input
                    v-model="filters.search"
                    type="search"
                    placeholder="توضیح، کاربر یا شناسه..."
                    class="h-10 w-full rounded-xl border bg-background pr-9 pl-3 text-sm"
                />
            </label>
            <select
                v-model="filters.event"
                class="h-10 rounded-xl border bg-background px-3 text-sm"
            >
                <option value="">همه رویدادها</option>
                <option value="created">ایجاد</option>
                <option value="updated">ویرایش</option>
                <option value="deleted">حذف</option>
            </select>
            <select
                v-model="filters.entity"
                class="h-10 rounded-xl border bg-background px-3 text-sm"
            >
                <option value="">همه موجودیت‌ها</option>
                <option
                    v-for="entity in model_options"
                    :key="entity"
                    :value="entity"
                >
                    {{ entity }}
                </option>
            </select>
            <select
                v-model="filters.date"
                class="h-10 rounded-xl border bg-background px-3 text-sm"
            >
                <option value="">همه زمان‌ها</option>
                <option value="today">امروز</option>
                <option value="7_days">۷ روز اخیر</option>
            </select>
            <div class="flex gap-2">
                <Button type="submit" class="h-10 flex-1 rounded-xl">
                    <Search class="size-4" /> فیلتر
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

        <section class="space-y-3">
            <article
                v-for="log in logs.data"
                :key="log.id"
                class="overflow-hidden rounded-2xl border bg-card"
            >
                <div
                    class="flex flex-col justify-between gap-3 border-b px-5 py-4 sm:flex-row sm:items-center"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                        >
                            <UserCog class="size-4" />
                        </span>
                        <div>
                            <p class="text-xs font-bold">
                                {{ log.description }}
                            </p>
                            <p class="mt-1 text-[10px] text-muted-foreground">
                                {{ log.actor?.name ?? 'سیستم' }}
                                <template v-if="log.actor">
                                    · {{ log.actor.email }}
                                </template>
                                · {{ log.entity }} #{{ log.entity_id }}
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            class="rounded-full px-2.5 py-1 text-[10px] font-semibold"
                            :class="eventMeta[log.event].className"
                        >
                            {{ eventMeta[log.event].label }}
                        </span>
                        <span
                            class="flex items-center gap-1 text-[9px] text-muted-foreground"
                        >
                            <Clock3 class="size-3" />
                            {{
                                log.created_at
                                    ? dateTime.format(new Date(log.created_at))
                                    : '—'
                            }}
                        </span>
                        <span
                            v-if="log.ip_address"
                            dir="ltr"
                            class="text-[9px] text-muted-foreground"
                        >
                            {{ log.ip_address }}
                        </span>
                    </div>
                </div>
                <details class="group">
                    <summary
                        class="flex cursor-pointer items-center gap-1.5 px-5 py-2.5 text-[10px] font-semibold text-primary"
                    >
                        <ShieldAlert class="size-3.5" />
                        مشاهده تغییرات قبل و بعد
                    </summary>
                    <div
                        class="grid gap-3 border-t bg-muted/20 p-4 md:grid-cols-2"
                    >
                        <div>
                            <p
                                class="mb-2 text-[9px] font-bold text-muted-foreground"
                            >
                                قبل
                            </p>
                            <pre
                                dir="ltr"
                                class="max-h-72 overflow-auto rounded-xl bg-background p-3 text-left text-[9px] leading-5"
                                >{{ valuesText(log.old_values) }}</pre>
                        </div>
                        <div>
                            <p
                                class="mb-2 text-[9px] font-bold text-muted-foreground"
                            >
                                بعد
                            </p>
                            <pre
                                dir="ltr"
                                class="max-h-72 overflow-auto rounded-xl bg-background p-3 text-left text-[9px] leading-5"
                                >{{ valuesText(log.new_values) }}</pre>
                        </div>
                    </div>
                </details>
            </article>
            <div
                v-if="!logs.data.length"
                class="rounded-2xl border bg-card px-6 py-14 text-center"
            >
                <History class="mx-auto size-10 text-muted-foreground/35" />
                <p class="mt-3 text-sm font-bold">رویدادی پیدا نشد</p>
            </div>
        </section>

        <div
            v-if="logs.last_page > 1"
            class="flex items-center justify-between rounded-2xl border bg-card px-5 py-4"
        >
            <span class="text-[10px] text-muted-foreground">
                {{ logs.from ?? 0 }} تا {{ logs.to ?? 0 }} از {{ logs.total }}
            </span>
            <div class="flex gap-1">
                <Button
                    variant="outline"
                    size="icon-sm"
                    :disabled="!logs.links[0]?.url"
                    @click="
                        logs.links[0]?.url && router.visit(logs.links[0]!.url!)
                    "
                >
                    <ChevronRight class="size-4" />
                </Button>
                <Button
                    v-for="(link, index) in logs.links.slice(1, -1)"
                    :key="`${link.label}-${index}`"
                    :variant="link.active ? 'default' : 'ghost'"
                    size="icon-sm"
                    :disabled="!link.url"
                    @click="link.url && router.visit(link.url)"
                >
                    {{ pageLabel(link.label) }}
                </Button>
                <Button
                    variant="outline"
                    size="icon-sm"
                    :disabled="!logs.links.at(-1)?.url"
                    @click="
                        logs.links.at(-1)?.url &&
                        router.visit(logs.links.at(-1)!.url!)
                    "
                >
                    <ChevronLeft class="size-4" />
                </Button>
            </div>
        </div>
    </div>
</template>
