<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    ClipboardCheck,
    Download,
    FileUp,
    Plus,
    Save,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import JalaliDatePicker from '@/components/JalaliDatePicker.vue';
import ProductPicker from '@/components/ProductPicker.vue';
import { Button } from '@/components/ui/button';
import {
    index as countsIndex,
    show as showCount,
    store as storeCount,
    update as updateCount,
} from '@/routes/stock-counts';

type CountRow = {
    product: { id: number; code: string; name: string; unit: string };
    counted_quantity: string;
    unit_cost?: string | null;
};
type CountDetail = {
    id: number;
    count_number: string;
    count_date: string;
    status: string;
    notes: string | null;
    warehouse: { id: number; code: string; name: string };
    items: CountRow[];
};
type FormItem = {
    product_id: string;
    product_code: string;
    product_name: string;
    unit: string;
    counted_quantity: string;
    unit_cost: string;
};

const props = defineProps<{
    count: CountDetail | null;
    warehouses: Array<{
        id: number;
        code: string;
        name: string;
        is_default: boolean;
    }>;
    can_view_costs: boolean;
}>();

const today = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Tehran',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
}).format(new Date());
const blank = (): FormItem => ({
    product_id: '',
    product_code: '',
    product_name: '',
    unit: '',
    counted_quantity: '0',
    unit_cost: '',
});
const form = useForm({
    submission_token: crypto.randomUUID(),
    warehouse_id:
        props.count?.warehouse.id.toString() ??
        props.warehouses
            .find((warehouse) => warehouse.is_default)
            ?.id.toString() ??
        '',
    count_date: props.count?.count_date ?? today,
    notes: props.count?.notes ?? '',
    items: props.count?.items.map((item) => ({
        product_id: item.product.id.toString(),
        product_code: item.product.code,
        product_name: item.product.name,
        unit: item.product.unit,
        counted_quantity: item.counted_quantity,
        unit_cost: item.unit_cost ?? '',
    })) ?? [blank()],
});
const serverError = ref<string | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const importing = ref(false);
const addItem = () => {
    if (form.items.length < 500) {
        form.items.push(blank());
    }
};
const removeItem = (index: number) => {
    if (form.items.length > 1) {
        form.items.splice(index, 1);
    }
};
const selectedProduct = (item: FormItem) =>
    item.product_id
        ? {
              id: Number(item.product_id),
              code: item.product_code,
              name: item.product_name,
              unit: item.unit,
          }
        : undefined;
const lineError = (index: number, field: string) =>
    (form.errors as Record<string, string | undefined>)[
        `items.${index}.${field}`
    ];
const submit = () => {
    serverError.value = null;
    form.clearErrors();

    if (form.items.some((item) => !item.product_id)) {
        serverError.value = 'کالای همه ردیف‌ها را انتخاب کنید.';
        return;
    }

    if (props.count) {
        form.put(updateCount(props.count.id).url, { preserveScroll: true });
    } else {
        form.post(storeCount().url, { preserveScroll: true });
    }
};
const importFile = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    importing.value = true;
    serverError.value = null;
    const data = new FormData();
    data.append('file', file);
    const token = document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.getAttribute('content');

    try {
        const response = await fetch('/inventory/counts/import', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            },
            credentials: 'same-origin',
            body: data,
        });
        const payload = (await response.json()) as {
            data?: Array<{
                product_id: number;
                code: string;
                name: string;
                unit: string;
                counted_quantity: string;
                unit_cost: string | null;
            }>;
            message?: string;
            errors?: { file?: string[] };
        };

        if (!response.ok || !payload.data) {
            serverError.value =
                payload.errors?.file?.[0] ??
                payload.message ??
                'ورود فایل انجام نشد.';
            return;
        }

        const existing = new Map(
            form.items
                .filter((item) => item.product_id)
                .map((item) => [Number(item.product_id), item] as const),
        );

        for (const item of payload.data) {
            const next: FormItem = {
                product_id: item.product_id.toString(),
                product_code: item.code,
                product_name: item.name,
                unit: item.unit,
                counted_quantity: item.counted_quantity,
                unit_cost: item.unit_cost ?? '',
            };
            const current = existing.get(item.product_id);

            if (current) {
                current.counted_quantity = next.counted_quantity;
                current.unit_cost = next.unit_cost;
                current.product_code = next.product_code;
                current.product_name = next.product_name;
                current.unit = next.unit;
            } else {
                existing.set(item.product_id, next);
            }
        }

        form.items = [...existing.values()];
    } catch {
        serverError.value = 'ارتباط برای خواندن فایل برقرار نشد.';
    } finally {
        importing.value = false;
        input.value = '';
    }
};
const downloadTemplate = () => {
    const blob = new Blob(['code,quantity,unit_cost\nSKU-001,10,125000\n'], {
        type: 'text/csv;charset=utf-8',
    });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'stock-count-template.csv';
    link.click();
    URL.revokeObjectURL(url);
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'شمارش موجودی', href: countsIndex() },
            { title: 'فرم شمارش', href: '#' },
        ],
    },
});
</script>

<template>
    <Head :title="count ? 'ویرایش شمارش' : 'شمارش جدید'" />

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
                        <span class="text-xs font-bold"
                            >کنترل شمارش فیزیکی</span
                        >
                    </div>
                    <h1 class="text-2xl font-black sm:text-3xl">
                        {{
                            count
                                ? `ویرایش ${count.count_number}`
                                : 'شمارش موجودی جدید'
                        }}
                    </h1>
                    <p class="mt-2 text-sm leading-7 text-muted-foreground">
                        مقدار واقعی شمارش‌شده را وارد کنید. موجودی سیستم هنگام
                        ثبت نهایی دوباره خوانده می‌شود.
                    </p>
                </div>
                <Button as-child variant="outline" class="w-fit rounded-xl">
                    <Link :href="count ? showCount(count.id) : countsIndex()">
                        <ArrowRight class="size-4" />
                        بازگشت
                    </Link>
                </Button>
            </div>
        </section>

        <form class="space-y-5" @submit.prevent="submit">
            <section
                class="grid gap-4 rounded-2xl border bg-card p-5 sm:grid-cols-3"
            >
                <label class="space-y-1.5">
                    <span class="text-xs font-semibold text-muted-foreground"
                        >انبار</span
                    >
                    <select
                        v-model="form.warehouse_id"
                        class="h-11 w-full rounded-xl border bg-background px-3 text-sm"
                    >
                        <option value="">انتخاب انبار</option>
                        <option
                            v-for="warehouse in warehouses"
                            :key="warehouse.id"
                            :value="warehouse.id"
                        >
                            {{ warehouse.name }} — {{ warehouse.code }}
                        </option>
                    </select>
                    <InputError :message="form.errors.warehouse_id" />
                </label>
                <label class="space-y-1.5">
                    <span class="text-xs font-semibold text-muted-foreground"
                        >تاریخ شمارش</span
                    >
                    <JalaliDatePicker v-model="form.count_date" required />
                    <InputError :message="form.errors.count_date" />
                </label>
                <label class="space-y-1.5">
                    <span class="text-xs font-semibold text-muted-foreground"
                        >یادداشت</span
                    >
                    <input
                        v-model="form.notes"
                        class="h-11 w-full rounded-xl border bg-background px-3 text-sm"
                        placeholder="توضیح شمارش..."
                    />
                    <InputError :message="form.errors.notes" />
                </label>
            </section>

            <section class="overflow-hidden rounded-2xl border bg-card">
                <div
                    class="flex items-center justify-between border-b px-5 py-4"
                >
                    <div>
                        <h2 class="text-sm font-bold">کالاهای شمارش‌شده</h2>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            برای هر کالا فقط یک مقدار شمارش ثبت کنید.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <input
                            ref="fileInput"
                            type="file"
                            accept=".csv,.txt,text/csv,text/plain"
                            class="hidden"
                            @change="importFile"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="importing"
                            @click="fileInput?.click()"
                        >
                            <FileUp class="size-4" />
                            {{ importing ? 'در حال خواندن...' : 'ورود CSV' }}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="downloadTemplate"
                        >
                            <Download class="size-4" />
                            نمونه فایل
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="addItem"
                        >
                            <Plus class="size-4" />
                            ردیف
                        </Button>
                    </div>
                </div>
                <div class="space-y-3 p-4">
                    <div
                        v-for="(item, index) in form.items"
                        :key="index"
                        class="grid gap-3 rounded-xl border bg-muted/15 p-3 md:grid-cols-[2fr_1fr_1fr_auto]"
                    >
                        <label class="space-y-1">
                            <span class="text-[10px] text-muted-foreground"
                                >کالا</span
                            >
                            <ProductPicker
                                v-model="item.product_id"
                                endpoint="/inventory/lookups/products"
                                :warehouse-id="form.warehouse_id"
                                :selected-label="
                                    item.product_name
                                        ? `${item.product_name} — ${item.product_code}`
                                        : ''
                                "
                                :exclude-ids="
                                    form.items
                                        .filter((_, i) => i !== index)
                                        .map((row) => Number(row.product_id))
                                        .filter(Boolean)
                                "
                                @select="
                                    (product) => {
                                        item.product_code = product.code;
                                        item.product_name = product.name;
                                        item.unit = product.unit;
                                    }
                                "
                            />
                            <InputError
                                :message="lineError(index, 'product_id')"
                            />
                        </label>
                        <label class="space-y-1">
                            <span class="text-[10px] text-muted-foreground">
                                مقدار شمارش‌شده
                                {{ selectedProduct(item)?.unit ?? '' }}
                            </span>
                            <input
                                v-model="item.counted_quantity"
                                inputmode="decimal"
                                class="h-10 w-full rounded-lg border bg-background px-2 text-left text-xs tabular-nums"
                            />
                            <InputError
                                :message="lineError(index, 'counted_quantity')"
                            />
                        </label>
                        <label v-if="can_view_costs" class="space-y-1">
                            <span class="text-[10px] text-muted-foreground"
                                >بهای واحد افزایشی</span
                            >
                            <input
                                v-model="item.unit_cost"
                                inputmode="decimal"
                                placeholder="خودکار از میانگین"
                                class="h-10 w-full rounded-lg border bg-background px-2 text-left text-xs tabular-nums"
                            />
                            <InputError
                                :message="lineError(index, 'unit_cost')"
                            />
                        </label>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="mt-[18px] size-10 text-red-600"
                            :disabled="form.items.length <= 1"
                            @click="removeItem(index)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>
            </section>

            <InputError
                :message="
                    serverError ??
                    (form.errors as Record<string, string | undefined>).count
                "
            />

            <div class="flex justify-end">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-xl px-6"
                >
                    <Save class="size-4" />
                    {{ count ? 'ذخیره پیش‌نویس' : 'ایجاد پیش‌نویس' }}
                </Button>
            </div>
        </form>
    </div>
</template>
