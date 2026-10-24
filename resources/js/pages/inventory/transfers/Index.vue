<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    ChevronLeft,
    ChevronRight,
    Plus,
    Search,
} from '@lucide/vue';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import {
    create as createTransfer,
    index as transfersIndex,
} from '@/routes/inventory-transfers';

const props = defineProps<{
    transfers: {
        data: Array<{
            id: number;
            transfer_number: string;
            transfer_date: string;
            quantity: string;
            reference: string | null;
            notes: string | null;
            source_warehouse: { id: number; code: string; name: string };
            destination_warehouse: { id: number; code: string; name: string };
            product: { id: number; code: string; name: string; unit: string };
            creator: string | null;
            unit_cost?: string | null;
            total_cost?: string | null;
        }>;
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: { warehouse_id: number | null };
    warehouses: Array<{
        id: number;
        code: string;
        name: string;
        is_default: boolean;
    }>;
    can_view_costs: boolean;
}>();

const filters = reactive({
    warehouse_id: props.filters.warehouse_id?.toString() ?? '',
});
const quantity = new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 3 });
const money = new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 2 });
const date = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    timeZone: 'UTC',
});
const applyFilters = () => {
    router.get(
        transfersIndex(),
        { warehouse_id: filters.warehouse_id || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
const goToPage = (url: string | null) => {
    if (url) {
        router.visit(url, { preserveState: true, preserveScroll: true });
    }
};
const pageLabel = (label: string) =>
    /^\d+$/.test(label)
        ? new Intl.NumberFormat('fa-IR').format(Number(label))
        : '…';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'انتقال انبار', href: transfersIndex() }],
    },
});
</script>

<template>
    <Head title="انتقال انبار" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="flex flex-col justify-between gap-5 rounded-3xl border border-primary/10 bg-card p-6 shadow-sm md:flex-row md:items-end"
        >
            <div>
                <div class="mb-3 flex items-center gap-2 text-primary">
                    <ArrowLeftRight class="size-5" />
                    <span class="text-xs font-bold">گردش بین انبارها</span>
                </div>
                <h1 class="text-2xl font-black sm:text-3xl">
                    انتقال‌های انبار
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    هر انتقال با دو حرکت خروج و ورود در یک transaction ثبت
                    می‌شود.
                </p>
            </div>
            <Button as-child class="rounded-xl">
                <Link :href="createTransfer()">
                    <Plus class="size-4" />
                    انتقال جدید
                </Link>
            </Button>
        </section>

        <form
            class="flex gap-2 rounded-2xl border bg-card p-4"
            @submit.prevent="applyFilters"
        >
            <select
                v-model="filters.warehouse_id"
                class="h-10 flex-1 rounded-xl border bg-background px-3 text-sm"
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
            <Button type="submit" class="h-10 rounded-xl">
                <Search class="size-4" /> فیلتر
            </Button>
        </form>

        <section class="overflow-hidden rounded-2xl border bg-card">
            <div v-if="transfers.data.length" class="overflow-x-auto">
                <table class="w-full min-w-[1000px] text-right text-sm">
                    <thead
                        class="bg-muted/35 text-[11px] text-muted-foreground"
                    >
                        <tr>
                            <th class="px-5 py-3">انتقال</th>
                            <th class="px-4 py-3">مسیر</th>
                            <th class="px-4 py-3">کالا</th>
                            <th class="px-4 py-3">مقدار</th>
                            <th class="px-4 py-3">تاریخ</th>
                            <template v-if="can_view_costs">
                                <th class="px-5 py-3">ارزش</th>
                            </template>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="transfer in transfers.data"
                            :key="transfer.id"
                        >
                            <td
                                class="px-5 py-4 font-mono text-xs font-bold text-primary"
                            >
                                {{ transfer.transfer_number }}
                                <p
                                    class="mt-1 font-sans text-[9px] text-muted-foreground"
                                >
                                    {{ transfer.creator || '—' }}
                                </p>
                            </td>
                            <td class="px-4 py-4 text-xs">
                                {{ transfer.source_warehouse.name }}
                                <span class="mx-1 text-muted-foreground"
                                    >←</span
                                >
                                {{ transfer.destination_warehouse.name }}
                            </td>
                            <td class="px-4 py-4 text-xs">
                                {{ transfer.product.name }}
                                <p
                                    class="mt-1 font-mono text-[9px] text-muted-foreground"
                                >
                                    {{ transfer.product.code }}
                                </p>
                            </td>
                            <td
                                class="px-4 py-4 text-xs font-bold tabular-nums"
                            >
                                {{ quantity.format(Number(transfer.quantity)) }}
                                {{ transfer.product.unit }}
                            </td>
                            <td class="px-4 py-4 text-xs">
                                {{
                                    date.format(
                                        new Date(
                                            `${transfer.transfer_date}T12:00:00Z`,
                                        ),
                                    )
                                }}
                            </td>
                            <td
                                v-if="can_view_costs"
                                class="px-5 py-4 text-xs tabular-nums"
                            >
                                {{
                                    money.format(
                                        Number(transfer.total_cost ?? 0),
                                    )
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="px-6 py-16 text-center">
                <ArrowLeftRight class="mx-auto size-10 text-muted-foreground" />
                <p class="mt-4 text-sm font-bold">انتقالی ثبت نشده است</p>
            </div>
            <div
                v-if="transfers.last_page > 1"
                class="flex items-center justify-between border-t px-5 py-4"
            >
                <p class="text-[11px] text-muted-foreground">
                    {{ transfers.from ?? 0 }} تا {{ transfers.to ?? 0 }} از
                    {{ transfers.total }}
                </p>
                <div class="flex items-center gap-1">
                    <Button
                        variant="outline"
                        size="icon-sm"
                        :disabled="!transfers.links[0]?.url"
                        @click="goToPage(transfers.links[0]?.url ?? null)"
                    >
                        <ChevronRight class="size-4" />
                    </Button>
                    <Button
                        v-for="(link, index) in transfers.links.slice(1, -1)"
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
                        :disabled="!transfers.links.at(-1)?.url"
                        @click="goToPage(transfers.links.at(-1)?.url ?? null)"
                    >
                        <ChevronLeft class="size-4" />
                    </Button>
                </div>
            </div>
        </section>
    </div>
</template>
