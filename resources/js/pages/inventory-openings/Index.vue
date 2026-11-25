<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus, Save, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, store } from '@/routes/inventory-openings';

type Product = {
    id: number;
    label: string;
    unit: string;
    unit_cost: string;
    track_serials: boolean;
};
type OpeningRow = {
    product_id: string;
    quantity: string;
    unit_cost: string;
    serial_numbers: string;
};
type Opening = {
    id: number;
    date: string;
    total: string;
    items_count: number;
    entry_number: string | null;
};

const props = defineProps<{
    products: Product[];
    warehouses: Array<{ id: number; name: string }>;
    default_date: string;
    openings: Opening[];
    reconciliation: { gl: string; subledger: string; difference: string };
}>();
const form = useForm({
    submission_token: crypto.randomUUID(),
    warehouse_id: props.warehouses[0]?.id.toString() ?? '',
    opening_date: props.default_date,
    items: [] as OpeningRow[],
});
const total = computed(() =>
    form.items.reduce(
        (sum, row) =>
            sum + Number(row.quantity || 0) * Number(row.unit_cost || 0),
        0,
    ),
);
const money = new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 2 });
const fieldError = (key: string) =>
    (form.errors as Record<string, string>)[key];
function addRow(productId = '') {
    const product = props.products.find(
        (item) => item.id === Number(productId),
    );
    form.items.push({
        product_id: productId,
        quantity: '1',
        unit_cost: product?.unit_cost ?? '0',
        serial_numbers: '',
    });
}
function selectProduct(index: number) {
    const product = props.products.find(
        (item) => item.id === Number(form.items[index].product_id),
    );
    if (product) form.items[index].unit_cost = product.unit_cost;
}
function submit() {
    form.transform((data) => ({
        ...data,
        items: data.items.map((row) => ({
            ...row,
            serial_numbers: row.serial_numbers
                ? row.serial_numbers
                      .split(/[\n,]+/)
                      .map((value) => value.trim())
                      .filter(Boolean)
                : [],
        })),
    })).post(store().url, {
        preserveScroll: true,
        onSuccess: () => {
            form.submission_token = crypto.randomUUID();
            form.items = [];
        },
    });
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'موجودی افتتاحیه', href: index() }],
    },
});
</script>

<template>
    <Head title="موجودی افتتاحیه" />
    <div class="mx-auto w-full max-w-6xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section>
            <h1 class="text-2xl font-black sm:text-3xl">موجودی افتتاحیه</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                movement و سند متوازن افتتاحیه در یک transaction ثبت می‌شوند.
            </p>
        </section>

        <section class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-2xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">مانده GL انبار</p>
                <p class="mt-2 text-xl font-black">
                    {{ money.format(Number(reconciliation.gl)) }}
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">ارزش subledger</p>
                <p class="mt-2 text-xl font-black">
                    {{ money.format(Number(reconciliation.subledger)) }}
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">اختلاف</p>
                <p
                    class="mt-2 text-xl font-black"
                    :class="
                        Number(reconciliation.difference) === 0
                            ? 'text-emerald-600'
                            : 'text-red-600'
                    "
                >
                    {{ money.format(Number(reconciliation.difference)) }}
                </p>
            </div>
        </section>

        <form
            class="space-y-5 rounded-2xl border bg-card p-5"
            @submit.prevent="submit"
        >
            <div
                v-if="fieldError('opening')"
                class="rounded-xl bg-red-500/10 p-3 text-xs text-red-700 dark:text-red-300"
            >
                {{ fieldError('opening') }}
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="warehouse_id">انبار *</Label>
                    <select
                        id="warehouse_id"
                        v-model="form.warehouse_id"
                        class="h-10 rounded-md border bg-background px-3 text-sm"
                    >
                        <option
                            v-for="warehouse in warehouses"
                            :key="warehouse.id"
                            :value="warehouse.id"
                        >
                            {{ warehouse.name }}
                        </option>
                    </select>
                    <InputError :message="fieldError('warehouse_id')" />
                </div>
                <div class="grid gap-2">
                    <Label for="opening_date">تاریخ افتتاحیه *</Label>
                    <Input
                        id="opening_date"
                        v-model="form.opening_date"
                        type="date"
                    />
                    <InputError :message="fieldError('opening_date')" />
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-right text-xs">
                    <thead class="bg-muted/40 text-[11px]">
                        <tr>
                            <th class="px-3 py-2">کالا</th>
                            <th class="px-3 py-2">مقدار</th>
                            <th class="px-3 py-2">بهای واحد</th>
                            <th class="px-3 py-2">سریال‌ها</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(row, index) in form.items" :key="index">
                            <td class="px-3 py-2">
                                <select
                                    v-model="row.product_id"
                                    class="h-9 rounded-md border bg-background px-2 text-xs"
                                    @change="selectProduct(index)"
                                >
                                    <option value="">انتخاب کنید</option>
                                    <option
                                        v-for="product in products"
                                        :key="product.id"
                                        :value="product.id"
                                    >
                                        {{ product.label }}
                                    </option>
                                </select>
                            </td>
                            <td class="px-3 py-2">
                                <Input
                                    v-model="row.quantity"
                                    inputmode="decimal"
                                    class="h-9"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <Input
                                    v-model="row.unit_cost"
                                    inputmode="decimal"
                                    class="h-9"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <Input
                                    v-model="row.serial_numbers"
                                    placeholder="با کاما یا خط جدید"
                                    class="h-9"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="form.items.splice(index, 1)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <Button
                    type="button"
                    variant="outline"
                    class="rounded-xl"
                    @click="addRow()"
                >
                    <Plus class="size-4" /> افزودن قلم
                </Button>
                <span class="text-sm font-bold"
                    >جمع: {{ money.format(total) }}</span
                >
                <Button
                    type="submit"
                    :disabled="form.processing || form.items.length === 0"
                    class="rounded-xl"
                >
                    <Spinner v-if="form.processing" />
                    <Save v-else class="size-4" />
                    ثبت افتتاحیه
                </Button>
            </div>
        </form>

        <section class="overflow-hidden rounded-2xl border bg-card">
            <h2 class="border-b p-4 text-sm font-bold">آخرین افتتاحیه‌ها</h2>
            <table class="w-full text-right text-xs">
                <tbody class="divide-y">
                    <tr v-for="opening in openings" :key="opening.id">
                        <td class="px-4 py-3">{{ opening.date }}</td>
                        <td class="px-4 py-3">{{ opening.items_count }} قلم</td>
                        <td class="px-4 py-3 font-bold">
                            {{ money.format(Number(opening.total)) }}
                        </td>
                        <td class="px-4 py-3 font-mono">
                            {{ opening.entry_number ?? '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
