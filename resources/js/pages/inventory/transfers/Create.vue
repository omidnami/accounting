<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeftRight, ArrowRight, Send } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import JalaliDatePicker from '@/components/JalaliDatePicker.vue';
import { Button } from '@/components/ui/button';
import {
    index as transfersIndex,
    store as storeTransfer,
} from '@/routes/inventory-transfers';

const props = defineProps<{
    warehouses: Array<{
        id: number;
        code: string;
        name: string;
        is_default: boolean;
    }>;
    products: Array<{
        id: number;
        code: string;
        name: string;
        unit: string;
    }>;
    can_view_costs: boolean;
}>();

const today = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Tehran',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
}).format(new Date());
const defaultWarehouse =
    props.warehouses.find((warehouse) => warehouse.is_default)?.id.toString() ??
    '';
const form = useForm({
    submission_token: crypto.randomUUID(),
    source_warehouse_id: defaultWarehouse,
    destination_warehouse_id: '',
    product_id: '',
    quantity: '',
    transfer_date: today,
    reference: '',
    notes: '',
});
const submit = () => {
    form.post(storeTransfer().url, { preserveScroll: true });
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'انتقال انبار', href: transfersIndex() },
            { title: 'انتقال جدید', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="انتقال جدید انبار" />

    <div class="mx-auto w-full max-w-5xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="rounded-3xl border border-primary/10 bg-card p-6 shadow-sm sm:p-8"
        >
            <div
                class="flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <ArrowLeftRight class="size-5" />
                        <span class="text-xs font-bold">انتقال کنترل‌شده</span>
                    </div>
                    <h1 class="text-2xl font-black sm:text-3xl">
                        انتقال کالا بین انبارها
                    </h1>
                    <p class="mt-2 text-sm leading-7 text-muted-foreground">
                        انتقال با دو حرکت متناظر و بهای میانگین مبدأ انجام
                        می‌شود و موجودی درآمد یا هزینه ایجاد نمی‌کند.
                    </p>
                </div>
                <Button as-child variant="outline" class="w-fit rounded-xl">
                    <Link :href="transfersIndex()">
                        <ArrowRight class="size-4" />
                        بازگشت
                    </Link>
                </Button>
            </div>
        </section>

        <form
            class="space-y-5 rounded-2xl border bg-card p-5 shadow-sm"
            @submit.prevent="submit"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-1.5">
                    <span class="text-xs font-semibold text-muted-foreground"
                        >انبار مبدأ</span
                    >
                    <select
                        v-model="form.source_warehouse_id"
                        class="h-11 w-full rounded-xl border bg-background px-3 text-sm"
                    >
                        <option value="">انتخاب مبدأ</option>
                        <option
                            v-for="warehouse in warehouses"
                            :key="warehouse.id"
                            :value="warehouse.id"
                        >
                            {{ warehouse.name }} — {{ warehouse.code }}
                        </option>
                    </select>
                    <InputError :message="form.errors.source_warehouse_id" />
                </label>
                <label class="space-y-1.5">
                    <span class="text-xs font-semibold text-muted-foreground"
                        >انبار مقصد</span
                    >
                    <select
                        v-model="form.destination_warehouse_id"
                        class="h-11 w-full rounded-xl border bg-background px-3 text-sm"
                    >
                        <option value="">انتخاب مقصد</option>
                        <option
                            v-for="warehouse in warehouses.filter(
                                (item) =>
                                    item.id.toString() !==
                                    form.source_warehouse_id,
                            )"
                            :key="warehouse.id"
                            :value="warehouse.id"
                        >
                            {{ warehouse.name }} — {{ warehouse.code }}
                        </option>
                    </select>
                    <InputError
                        :message="form.errors.destination_warehouse_id"
                    />
                </label>
                <label class="space-y-1.5">
                    <span class="text-xs font-semibold text-muted-foreground"
                        >کالا</span
                    >
                    <select
                        v-model="form.product_id"
                        class="h-11 w-full rounded-xl border bg-background px-3 text-sm"
                    >
                        <option value="">انتخاب کالا</option>
                        <option
                            v-for="product in products"
                            :key="product.id"
                            :value="product.id"
                        >
                            {{ product.name }} — {{ product.code }}
                        </option>
                    </select>
                    <InputError :message="form.errors.product_id" />
                </label>
                <label class="space-y-1.5">
                    <span class="text-xs font-semibold text-muted-foreground"
                        >مقدار</span
                    >
                    <input
                        v-model="form.quantity"
                        inputmode="decimal"
                        class="h-11 w-full rounded-xl border bg-background px-3 text-left text-sm tabular-nums"
                        placeholder="مثلاً ۵"
                    />
                    <InputError :message="form.errors.quantity" />
                </label>
                <label class="space-y-1.5">
                    <span class="text-xs font-semibold text-muted-foreground"
                        >تاریخ انتقال</span
                    >
                    <JalaliDatePicker v-model="form.transfer_date" required />
                    <InputError :message="form.errors.transfer_date" />
                </label>
                <label class="space-y-1.5">
                    <span class="text-xs font-semibold text-muted-foreground"
                        >مرجع</span
                    >
                    <input
                        v-model="form.reference"
                        class="h-11 w-full rounded-xl border bg-background px-3 text-sm"
                        placeholder="اختیاری"
                    />
                    <InputError :message="form.errors.reference" />
                </label>
            </div>
            <label class="block space-y-1.5">
                <span class="text-xs font-semibold text-muted-foreground"
                    >یادداشت</span
                >
                <textarea
                    v-model="form.notes"
                    rows="3"
                    class="w-full rounded-xl border bg-background px-3 py-2 text-sm"
                />
                <InputError :message="form.errors.notes" />
            </label>
            <InputError
                :message="
                    (form.errors as Record<string, string | undefined>).transfer
                "
            />
            <div class="flex justify-end">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-xl px-6"
                >
                    <Send class="size-4" />
                    ثبت انتقال
                </Button>
            </div>
        </form>
    </div>
</template>
