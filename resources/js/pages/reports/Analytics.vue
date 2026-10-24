<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    BarChart3,
    Boxes,
    CalendarRange,
    Layers3,
    PackageCheck,
    Search,
} from '@lucide/vue';
import { ref } from 'vue';
import JalaliDatePicker from '@/components/JalaliDatePicker.vue';
import { Button } from '@/components/ui/button';
import { analytics as analyticsReport } from '@/routes/reports';

type AnalyticsRow = {
    label: string;
    quantity: string;
    revenue: string;
    cogs: string;
    gross_profit: string;
};
type VariantRow = AnalyticsRow & { code: string; category: string | null };
type BatchRow = {
    batch_number: string;
    expiry_date: string | null;
    code: string;
    name: string;
    quantity: string;
    cogs: string;
};
type TopRow = {
    product_id: number;
    code: string;
    name: string;
    quantity: string;
    revenue: string;
};

const props = defineProps<{
    filters: {
        from: string;
        to: string;
        month_start: string;
        month_end: string;
    };
    categories: AnalyticsRow[];
    variants: VariantRow[];
    batches: BatchRow[];
    top_month: TopRow[];
}>();
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const money = new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 2 });
const quantity = new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 3 });
const applyFilters = () =>
    router.get(
        analyticsReport(),
        { from: from.value, to: to.value },
        { preserveScroll: true, preserveState: true },
    );

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'تحلیل کالا و Batch', href: analyticsReport() }],
    },
});
</script>

<template>
    <Head title="تحلیل کالا و Batch" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="rounded-3xl border border-primary/10 bg-card p-6 sm:p-8"
        >
            <div class="flex items-center gap-2 text-primary">
                <BarChart3 class="size-5" />
                <span class="text-xs font-bold">تحلیل پیشرفته کالا</span>
            </div>
            <h1 class="mt-3 text-2xl font-black sm:text-3xl">
                دسته، تنوع، Batch و کالاهای پرفروش
            </h1>
        </section>

        <form
            class="grid gap-3 rounded-2xl border bg-card p-4 sm:grid-cols-[1fr_1fr_auto]"
            @submit.prevent="applyFilters"
        >
            <JalaliDatePicker v-model="from" />
            <JalaliDatePicker v-model="to" />
            <Button type="submit" class="rounded-xl">
                <Search class="size-4" /> اعمال
            </Button>
        </form>

        <div class="grid gap-5 xl:grid-cols-2">
            <section class="overflow-hidden rounded-2xl border bg-card">
                <div class="flex items-center gap-2 border-b px-5 py-4">
                    <Layers3 class="size-4 text-primary" />
                    <h2 class="text-sm font-bold">فروش بر اساس دسته</h2>
                </div>
                <table class="w-full text-right text-xs">
                    <thead class="bg-muted/30">
                        <tr>
                            <th class="px-4 py-3">دسته</th>
                            <th class="px-4 py-3">مقدار</th>
                            <th class="px-4 py-3">فروش</th>
                            <th class="px-4 py-3">سود</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="row in categories" :key="row.label">
                            <td class="px-4 py-3 font-semibold">
                                {{ row.label }}
                            </td>
                            <td class="px-4 py-3">
                                {{ quantity.format(Number(row.quantity)) }}
                            </td>
                            <td class="px-4 py-3">
                                {{ money.format(Number(row.revenue)) }}
                            </td>
                            <td class="px-4 py-3">
                                {{ money.format(Number(row.gross_profit)) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="overflow-hidden rounded-2xl border bg-card">
                <div class="flex items-center gap-2 border-b px-5 py-4">
                    <Boxes class="size-4 text-primary" />
                    <h2 class="text-sm font-bold">فروش تنوع‌ها</h2>
                </div>
                <table class="w-full text-right text-xs">
                    <thead class="bg-muted/30">
                        <tr>
                            <th class="px-4 py-3">تنوع</th>
                            <th class="px-4 py-3">مقدار</th>
                            <th class="px-4 py-3">فروش</th>
                            <th class="px-4 py-3">سود</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="row in variants" :key="row.code">
                            <td class="px-4 py-3">
                                <p class="font-semibold">{{ row.label }}</p>
                                <p
                                    class="mt-1 font-mono text-[9px] text-muted-foreground"
                                >
                                    {{ row.code }}
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                {{ quantity.format(Number(row.quantity)) }}
                            </td>
                            <td class="px-4 py-3">
                                {{ money.format(Number(row.revenue)) }}
                            </td>
                            <td class="px-4 py-3">
                                {{ money.format(Number(row.gross_profit)) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>

        <section class="overflow-hidden rounded-2xl border bg-card">
            <div class="flex items-center gap-2 border-b px-5 py-4">
                <CalendarRange class="size-4 text-primary" />
                <h2 class="text-sm font-bold">کالاهای پرفروش ماه</h2>
                <span class="text-[10px] text-muted-foreground">
                    {{ filters.month_start }} تا {{ filters.month_end }}
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[700px] text-right text-xs">
                    <thead class="bg-muted/30">
                        <tr>
                            <th class="px-4 py-3">کالا</th>
                            <th class="px-4 py-3">مقدار</th>
                            <th class="px-4 py-3">فروش</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="row in top_month" :key="row.product_id">
                            <td class="px-4 py-3">
                                {{ row.name }}
                                <span
                                    class="font-mono text-[9px] text-muted-foreground"
                                    >{{ row.code }}</span
                                >
                            </td>
                            <td class="px-4 py-3">
                                {{ quantity.format(Number(row.quantity)) }}
                            </td>
                            <td class="px-4 py-3">
                                {{ money.format(Number(row.revenue)) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border bg-card">
            <div class="flex items-center gap-2 border-b px-5 py-4">
                <PackageCheck class="size-4 text-primary" />
                <h2 class="text-sm font-bold">فروش و COGS بر اساس Batch</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[800px] text-right text-xs">
                    <thead class="bg-muted/30">
                        <tr>
                            <th class="px-4 py-3">Batch</th>
                            <th class="px-4 py-3">کالا</th>
                            <th class="px-4 py-3">انقضا</th>
                            <th class="px-4 py-3">مقدار فروش</th>
                            <th class="px-4 py-3">COGS</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="row in batches" :key="row.batch_number">
                            <td class="px-4 py-3 font-mono">
                                {{ row.batch_number }}
                            </td>
                            <td class="px-4 py-3">
                                {{ row.name }} · {{ row.code }}
                            </td>
                            <td class="px-4 py-3">
                                {{ row.expiry_date ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                {{ quantity.format(Number(row.quantity)) }}
                            </td>
                            <td class="px-4 py-3">
                                {{ money.format(Number(row.cogs)) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
