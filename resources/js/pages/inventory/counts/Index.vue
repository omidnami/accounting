<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ClipboardCheck,
    ChevronLeft,
    ChevronRight,
    Eye,
    PencilLine,
    Plus,
    Search,
    Warehouse,
} from '@lucide/vue';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import {
    create as createCount,
    edit as editCount,
    index as countsIndex,
    show as showCount,
} from '@/routes/stock-counts';

type Status = 'draft' | 'posted' | 'cancelled';
const props = defineProps<{
    counts: {
        data: Array<{
            id: number;
            count_number: string;
            count_date: string;
            status: Status;
            notes: string | null;
            warehouse: { id: number; code: string; name: string };
            creator: string | null;
            item_count: number;
            difference_count: number;
        }>;
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: { status: Status | null; warehouse_id: number | null };
    warehouses: Array<{
        id: number;
        code: string;
        name: string;
        is_default: boolean;
    }>;
    can_post: boolean;
}>();

const filters = reactive({
    status: props.filters.status ?? '',
    warehouse_id: props.filters.warehouse_id?.toString() ?? '',
});
const count = new Intl.NumberFormat('fa-IR');
const date = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    timeZone: 'UTC',
});
const statusMeta: Record<Status, { label: string; className: string }> = {
    draft: {
        label: 'پیش‌نویس',
        className: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    },
    posted: {
        label: 'ثبت‌شده',
        className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    },
    cancelled: {
        label: 'لغوشده',
        className: 'bg-muted text-muted-foreground',
    },
};
const formatDate = (value: string) =>
    date.format(new Date(`${value}T12:00:00Z`));
const applyFilters = () => {
    router.get(
        countsIndex(),
        {
            status: filters.status || undefined,
            warehouse_id: filters.warehouse_id || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
const goToPage = (url: string | null) => {
    if (url) {
        router.visit(url, { preserveState: true, preserveScroll: true });
    }
};
const pageLabel = (label: string) =>
    /^\d+$/.test(label) ? count.format(Number(label)) : '…';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'شمارش موجودی', href: countsIndex() }],
    },
});
</script>

<template>
    <Head title="شمارش موجودی" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <ClipboardCheck class="size-5" />
                        <span class="text-xs font-bold"
                            >کنترل فیزیکی انبار</span
                        >
                    </div>
                    <h1 class="text-2xl font-black sm:text-3xl">
                        شمارش و تعدیل موجودی
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        شمارش ابتدا پیش‌نویس است؛ مدیر یا حسابدار پس از بررسی،
                        حرکت انبار و سند تعدیل را اتمیک ثبت می‌کند.
                    </p>
                </div>
                <Button as-child class="h-11 rounded-xl px-5">
                    <Link :href="createCount()">
                        <Plus class="size-4" />
                        شمارش جدید
                    </Link>
                </Button>
            </div>
        </section>

        <form
            class="grid gap-3 rounded-2xl border border-border/70 bg-card p-4 shadow-sm sm:grid-cols-[1fr_1fr_auto]"
            @submit.prevent="applyFilters"
        >
            <select
                v-model="filters.warehouse_id"
                class="h-10 rounded-xl border border-input bg-background px-3 text-sm"
            >
                <option value="">همه انبارها</option>
                <option
                    v-for="warehouse in warehouses"
                    :key="warehouse.id"
                    :value="warehouse.id"
                >
                    {{ warehouse.name }}
                </option>
            </select>
            <select
                v-model="filters.status"
                class="h-10 rounded-xl border border-input bg-background px-3 text-sm"
            >
                <option value="">همه وضعیت‌ها</option>
                <option value="draft">پیش‌نویس</option>
                <option value="posted">ثبت‌شده</option>
                <option value="cancelled">لغوشده</option>
            </select>
            <Button type="submit" class="h-10 rounded-xl px-5">
                <Search class="size-4" />
                فیلتر
            </Button>
        </form>

        <section
            class="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
        >
            <div v-if="counts.data.length" class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-right">
                    <thead
                        class="bg-muted/35 text-[11px] text-muted-foreground"
                    >
                        <tr>
                            <th class="px-5 py-3">شمارش</th>
                            <th class="px-4 py-3">انبار</th>
                            <th class="px-4 py-3">تاریخ</th>
                            <th class="px-4 py-3">اقلام / مغایرت</th>
                            <th class="px-4 py-3">وضعیت</th>
                            <th class="px-5 py-3 text-left">عملیات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        <tr v-for="item in counts.data" :key="item.id">
                            <td class="px-5 py-4">
                                <Link
                                    :href="showCount(item.id)"
                                    class="font-mono text-xs font-bold text-primary"
                                >
                                    {{ item.count_number }}
                                </Link>
                                <p
                                    class="mt-1 text-[10px] text-muted-foreground"
                                >
                                    {{ item.creator || '—' }}
                                </p>
                            </td>
                            <td class="px-4 py-4 text-xs">
                                {{ item.warehouse.name }}
                                <p
                                    class="mt-1 font-mono text-[9px] text-muted-foreground"
                                >
                                    {{ item.warehouse.code }}
                                </p>
                            </td>
                            <td class="px-4 py-4 text-xs">
                                {{ formatDate(item.count_date) }}
                            </td>
                            <td class="px-4 py-4 text-xs">
                                {{ count.format(item.item_count) }} قلم
                                <p
                                    class="mt-1 text-[10px] text-muted-foreground"
                                >
                                    {{ count.format(item.difference_count) }}
                                    مغایرت
                                </p>
                            </td>
                            <td class="px-4 py-4">
                                <span
                                    class="rounded-full px-2.5 py-1 text-[10px] font-semibold"
                                    :class="statusMeta[item.status].className"
                                >
                                    {{ statusMeta[item.status].label }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-left">
                                <div class="flex justify-end gap-1">
                                    <Button as-child variant="ghost" size="sm">
                                        <Link :href="showCount(item.id)">
                                            <Eye class="size-4" />
                                            مشاهده
                                        </Link>
                                    </Button>
                                    <Button
                                        v-if="item.status === 'draft'"
                                        as-child
                                        variant="ghost"
                                        size="sm"
                                    >
                                        <Link :href="editCount(item.id)">
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
                <Warehouse class="mx-auto size-10 text-muted-foreground" />
                <p class="mt-4 text-sm font-bold">شمارشی ثبت نشده است</p>
            </div>
            <div
                v-if="counts.last_page > 1"
                class="flex items-center justify-between border-t px-5 py-4"
            >
                <p class="text-[11px] text-muted-foreground">
                    {{ count.format(counts.from ?? 0) }} تا
                    {{ count.format(counts.to ?? 0) }} از
                    {{ count.format(counts.total) }}
                </p>
                <div class="flex items-center gap-1">
                    <Button
                        variant="outline"
                        size="icon-sm"
                        :disabled="!counts.links[0]?.url"
                        @click="goToPage(counts.links[0]?.url ?? null)"
                    >
                        <ChevronRight class="size-4" />
                    </Button>
                    <Button
                        v-for="(link, index) in counts.links.slice(1, -1)"
                        :key="`${link.label}-${index}`"
                        :variant="link.active ? 'default' : 'ghost'"
                        size="icon-sm"
                        :disabled="!link.url"
                        @click="goToPage(link.url)"
                    >
                        {{ pageLabel(link.label) }}
                    </Button>
                    <Button
                        variant="outline"
                        size="icon-sm"
                        :disabled="!counts.links.at(-1)?.url"
                        @click="goToPage(counts.links.at(-1)?.url ?? null)"
                    >
                        <ChevronLeft class="size-4" />
                    </Button>
                </div>
            </div>
        </section>
    </div>
</template>
