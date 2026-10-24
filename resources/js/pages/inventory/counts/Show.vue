<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    CheckCircle2,
    ClipboardCheck,
    PencilLine,
    Trash2,
} from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    destroy as destroyCount,
    edit as editCount,
    index as countsIndex,
    post as postCount,
} from '@/routes/stock-counts';

type Item = {
    id: number;
    product: { id: number; code: string; name: string; unit: string };
    system_quantity: string;
    counted_quantity: string;
    difference_quantity: string;
    unit_cost?: string | null;
    adjustment_value?: string;
};
const props = defineProps<{
    count: {
        id: number;
        count_number: string;
        count_date: string;
        status: 'draft' | 'posted' | 'cancelled';
        notes: string | null;
        warehouse: { id: number; code: string; name: string };
        creator: string | null;
        poster: string | null;
        posted_at: string | null;
        items: Item[];
    };
    can_post: boolean;
    can_view_costs: boolean;
}>();

const page = usePage();
const errors = computed(() => page.props.errors);
const quantity = new Intl.NumberFormat('fa-IR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 3,
});
const money = new Intl.NumberFormat('fa-IR', {
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
const statusLabel = computed(() =>
    props.count.status === 'draft'
        ? 'پیش‌نویس'
        : props.count.status === 'posted'
          ? 'ثبت‌شده'
          : 'لغوشده',
);
const postCurrent = () => {
    if (
        !window.confirm(
            'شمارش ثبت شود؟ اختلاف‌ها با موجودی فعلی محاسبه، حرکت انبار و سند تعدیل به‌صورت اتمیک ثبت می‌شوند.',
        )
    ) {
        return;
    }

    router.post(postCount(props.count.id), {}, { preserveScroll: true });
};
const deleteCurrent = () => {
    if (window.confirm('پیش‌نویس شمارش حذف شود؟')) {
        router.delete(destroyCount(props.count.id));
    }
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'شمارش موجودی', href: countsIndex() },
            { title: 'جزئیات شمارش', href: '#' },
        ],
    },
});
</script>

<template>
    <Head :title="`شمارش ${count.count_number}`" />

    <div class="mx-auto w-full max-w-6xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="rounded-3xl border border-primary/10 bg-card p-6 shadow-sm sm:p-8"
        >
            <div
                class="flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <ClipboardCheck class="size-5" />
                        <span class="text-xs font-bold">{{ statusLabel }}</span>
                    </div>
                    <h1 class="font-mono text-2xl font-black sm:text-3xl">
                        {{ count.count_number }}
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        {{ count.warehouse.name }} ·
                        {{ formatDate(count.count_date) }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="count.status === 'draft' && can_post"
                        class="rounded-xl"
                        @click="postCurrent"
                    >
                        <CheckCircle2 class="size-4" />
                        ثبت و اعمال تعدیل
                    </Button>
                    <Button
                        v-if="count.status === 'draft'"
                        as-child
                        variant="outline"
                        class="rounded-xl"
                    >
                        <Link :href="editCount(count.id)">
                            <PencilLine class="size-4" />
                            ویرایش
                        </Link>
                    </Button>
                    <Button
                        v-if="count.status === 'draft'"
                        variant="ghost"
                        class="rounded-xl text-red-600"
                        @click="deleteCurrent"
                    >
                        <Trash2 class="size-4" />
                        حذف
                    </Button>
                </div>
            </div>
        </section>

        <InputError
            :message="(errors as Record<string, string | undefined>).count"
        />

        <section class="overflow-hidden rounded-2xl border bg-card">
            <div class="border-b px-5 py-4">
                <h2 class="text-sm font-bold">اختلاف شمارش</h2>
                <p class="mt-1 text-[10px] text-muted-foreground">
                    مقادیر سیستم هنگام ثبت نهایی دوباره کنترل می‌شوند.
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[850px] text-right text-sm">
                    <thead
                        class="bg-muted/35 text-[11px] text-muted-foreground"
                    >
                        <tr>
                            <th class="px-5 py-3">کالا</th>
                            <th class="px-4 py-3">موجودی سیستم</th>
                            <th class="px-4 py-3">شمارش‌شده</th>
                            <th class="px-4 py-3">اختلاف</th>
                            <template v-if="can_view_costs">
                                <th class="px-4 py-3">بهای واحد</th>
                                <th class="px-5 py-3">ارزش تعدیل</th>
                            </template>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="item in count.items" :key="item.id">
                            <td class="px-5 py-4">
                                <p class="text-xs font-bold">
                                    {{ item.product.name }}
                                </p>
                                <p
                                    class="mt-1 font-mono text-[9px] text-muted-foreground"
                                >
                                    {{ item.product.code }}
                                </p>
                            </td>
                            <td class="px-4 py-4 text-xs tabular-nums">
                                {{
                                    quantity.format(
                                        Number(item.system_quantity),
                                    )
                                }}
                            </td>
                            <td
                                class="px-4 py-4 text-xs font-bold tabular-nums"
                            >
                                {{
                                    quantity.format(
                                        Number(item.counted_quantity),
                                    )
                                }}
                                {{ item.product.unit }}
                            </td>
                            <td
                                class="px-4 py-4 text-xs font-black tabular-nums"
                                :class="
                                    Number(item.difference_quantity) > 0
                                        ? 'text-emerald-600'
                                        : Number(item.difference_quantity) < 0
                                          ? 'text-red-600'
                                          : 'text-muted-foreground'
                                "
                            >
                                {{
                                    quantity.format(
                                        Number(item.difference_quantity),
                                    )
                                }}
                            </td>
                            <template v-if="can_view_costs">
                                <td class="px-4 py-4 text-xs tabular-nums">
                                    {{
                                        item.unit_cost
                                            ? money.format(
                                                  Number(item.unit_cost),
                                              )
                                            : '—'
                                    }}
                                </td>
                                <td
                                    class="px-5 py-4 text-xs font-semibold tabular-nums"
                                >
                                    {{
                                        money.format(
                                            Number(item.adjustment_value ?? 0),
                                        )
                                    }}
                                </td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Button as-child variant="outline" class="rounded-xl">
            <Link :href="countsIndex()">
                <ArrowRight class="size-4" />
                بازگشت به فهرست
            </Link>
        </Button>
    </div>
</template>
