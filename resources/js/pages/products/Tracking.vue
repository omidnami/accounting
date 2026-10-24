<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Barcode,
    CalendarClock,
    CircleOff,
    PackageCheck,
    Plus,
} from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import JalaliDatePicker from '@/components/JalaliDatePicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    index as productsIndex,
    tracking as trackingProduct,
    update as updateProduct,
} from '@/routes/products';
import tracking from '@/routes/products/tracking';

type Batch = {
    id: number;
    batch_number: string;
    production_date: string | null;
    expiry_date: string | null;
    notes: string | null;
    serials_count: number;
    status: 'valid' | 'expiring' | 'expired' | 'no_expiry';
};
type Serial = {
    id: number;
    serial_number: string;
    status: string;
    status_label: string;
    batch: { id: number; batch_number: string } | null;
    warehouse: { id: number; code: string; name: string } | null;
    acquired_at: string | null;
    notes: string | null;
};

const props = defineProps<{
    product: {
        id: number;
        code: string;
        name: string;
        unit: string;
        category: { code: string; name: string } | null;
        parent: { id: number; code: string; name: string } | null;
        is_variant_parent: boolean;
    };
    batches: Batch[];
    serials: Serial[];
    warehouses: Array<{
        id: number;
        code: string;
        name: string;
        is_default: boolean;
    }>;
    summary: {
        batches: number;
        expiring: number;
        expired: number;
        available_serials: number;
    };
}>();

const batchForm = useForm({
    batch_number: '',
    production_date: '',
    expiry_date: '',
    notes: '',
});
const serialForm = useForm({
    serial_numbers_text: '',
    product_batch_id: '',
    warehouse_id:
        props.warehouses
            .find((warehouse) => warehouse.is_default)
            ?.id.toString() ??
        props.warehouses[0]?.id.toString() ??
        '',
    acquired_at: '',
    notes: '',
});
const submitBatch = () =>
    batchForm.post(tracking.batches.store(props.product.id).url, {
        preserveScroll: true,
        onSuccess: () => batchForm.reset(),
    });
const submitSerials = () =>
    serialForm.post(tracking.serials.store(props.product.id).url, {
        preserveScroll: true,
        onSuccess: () => serialForm.reset('serial_numbers_text', 'notes'),
    });
const voidSerial = (serial: Serial) => {
    if (!window.confirm(`سریال ${serial.serial_number} باطل شود؟`)) {
        return;
    }

    router.patch(
        tracking.serials.void({
            product: props.product.id,
            serial: serial.id,
        }).url,
    );
};
const batchClass = (status: Batch['status']) =>
    ({
        valid: 'bg-emerald-500/10 text-emerald-700',
        expiring: 'bg-amber-500/10 text-amber-700',
        expired: 'bg-red-500/10 text-red-700',
        no_expiry: 'bg-muted text-muted-foreground',
    })[status];

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'کالا و موجودی', href: productsIndex() },
            { title: 'Batch و سریال', href: '#' },
        ],
    },
});
</script>

<template>
    <Head :title="`ردیابی ${product.name}`" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="flex flex-col justify-between gap-4 rounded-3xl border border-primary/10 bg-card p-6 sm:flex-row sm:items-end"
        >
            <div>
                <div class="mb-3 flex items-center gap-2 text-primary">
                    <PackageCheck class="size-5" />
                    <span class="text-xs font-bold">ردیابی کالا</span>
                </div>
                <h1 class="text-2xl font-black sm:text-3xl">
                    {{ product.name }}
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{ product.code }} · موجودی انبار همچنان در سطح SKU کنترل
                    می‌شود و Batch و سریال شناسه‌های ردیابی آن هستند.
                </p>
            </div>
            <div class="flex gap-2">
                <Button as-child variant="outline" class="rounded-xl">
                    <Link :href="updateProduct(product.id)">
                        <ArrowRight class="size-4" /> ویرایش کالا
                    </Link>
                </Button>
                <Button as-child variant="outline" class="rounded-xl">
                    <Link :href="trackingProduct(product.id)"> تازه‌سازی </Link>
                </Button>
            </div>
        </section>

        <p
            v-if="product.is_variant_parent"
            class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-4 text-sm text-amber-700"
        >
            این کالا والد تنوع است. Batch و سریال را روی هر تنوع ثبت کنید.
        </p>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-2xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Batchها</p>
                <p class="mt-2 text-2xl font-black">{{ summary.batches }}</p>
            </article>
            <article class="rounded-2xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">در حال انقضا</p>
                <p class="mt-2 text-2xl font-black text-amber-600">
                    {{ summary.expiring }}
                </p>
            </article>
            <article class="rounded-2xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">منقضی</p>
                <p class="mt-2 text-2xl font-black text-red-600">
                    {{ summary.expired }}
                </p>
            </article>
            <article class="rounded-2xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">سریال موجود</p>
                <p class="mt-2 text-2xl font-black">
                    {{ summary.available_serials }}
                </p>
            </article>
        </section>

        <div class="grid items-start gap-5 xl:grid-cols-2">
            <form
                class="rounded-2xl border bg-card p-5"
                @submit.prevent="submitBatch"
            >
                <div class="flex items-center gap-2">
                    <CalendarClock class="size-5 text-primary" />
                    <h2 class="text-sm font-bold">ثبت Batch</h2>
                </div>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <Label for="batch-number">شماره Batch *</Label>
                        <Input
                            id="batch-number"
                            v-model="batchForm.batch_number"
                            class="mt-2"
                        />
                        <InputError :message="batchForm.errors.batch_number" />
                    </div>
                    <div>
                        <Label>تاریخ تولید</Label>
                        <JalaliDatePicker
                            v-model="batchForm.production_date"
                            class="mt-2"
                        />
                    </div>
                    <div>
                        <Label>تاریخ انقضا</Label>
                        <JalaliDatePicker
                            v-model="batchForm.expiry_date"
                            :min="batchForm.production_date"
                        />
                        <InputError :message="batchForm.errors.expiry_date" />
                    </div>
                    <div class="sm:col-span-2">
                        <Label for="batch-notes">توضیحات</Label>
                        <Input
                            id="batch-notes"
                            v-model="batchForm.notes"
                            class="mt-2"
                        />
                    </div>
                </div>
                <InputError
                    class="mt-2"
                    :message="
                        (batchForm.errors as Record<string, string | undefined>)
                            .tracking
                    "
                />
                <Button
                    type="submit"
                    class="mt-4 rounded-xl"
                    :disabled="batchForm.processing"
                >
                    <Plus class="size-4" /> ثبت Batch
                </Button>
            </form>

            <form
                class="rounded-2xl border bg-card p-5"
                @submit.prevent="submitSerials"
            >
                <div class="flex items-center gap-2">
                    <Barcode class="size-5 text-primary" />
                    <h2 class="text-sm font-bold">ثبت گروهی سریال</h2>
                </div>
                <div class="mt-4 space-y-4">
                    <div>
                        <Label for="serial-numbers">هر سریال در یک خط *</Label>
                        <textarea
                            id="serial-numbers"
                            v-model="serialForm.serial_numbers_text"
                            rows="5"
                            dir="ltr"
                            class="mt-2 w-full rounded-xl border bg-background px-3 py-2 text-left font-mono text-sm"
                            placeholder="SN-1001&#10;SN-1002"
                        />
                        <InputError
                            :message="serialForm.errors.serial_numbers_text"
                        />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <select
                            v-model="serialForm.product_batch_id"
                            class="h-11 rounded-xl border px-3 text-sm"
                        >
                            <option value="">بدون Batch</option>
                            <option
                                v-for="batch in batches"
                                :key="batch.id"
                                :value="batch.id.toString()"
                            >
                                {{ batch.batch_number }}
                            </option>
                        </select>
                        <select
                            v-model="serialForm.warehouse_id"
                            class="h-11 rounded-xl border px-3 text-sm"
                        >
                            <option value="">بدون انبار</option>
                            <option
                                v-for="warehouse in warehouses"
                                :key="warehouse.id"
                                :value="warehouse.id.toString()"
                            >
                                {{ warehouse.name }}
                            </option>
                        </select>
                    </div>
                    <JalaliDatePicker
                        v-model="serialForm.acquired_at"
                        placeholder="تاریخ ورود"
                    />
                </div>
                <InputError
                    class="mt-2"
                    :message="
                        (
                            serialForm.errors as Record<
                                string,
                                string | undefined
                            >
                        ).tracking
                    "
                />
                <Button
                    type="submit"
                    class="mt-4 rounded-xl"
                    :disabled="serialForm.processing"
                >
                    <Plus class="size-4" /> ثبت سریال‌ها
                </Button>
            </form>
        </div>

        <section class="overflow-hidden rounded-2xl border bg-card">
            <div class="border-b px-5 py-4">
                <h2 class="text-sm font-bold">Batchها و انقضا</h2>
            </div>
            <div class="divide-y">
                <div
                    v-for="batch in batches"
                    :key="batch.id"
                    class="flex items-center justify-between gap-3 px-5 py-3"
                >
                    <div>
                        <p class="font-mono text-sm font-bold">
                            {{ batch.batch_number }}
                        </p>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            {{ batch.serials_count }} سریال
                            <template v-if="batch.expiry_date">
                                · انقضا {{ batch.expiry_date }}</template
                            >
                        </p>
                    </div>
                    <span
                        class="rounded-full px-2 py-1 text-[9px]"
                        :class="batchClass(batch.status)"
                    >
                        {{
                            batch.status === 'expired'
                                ? 'منقضی'
                                : batch.status === 'expiring'
                                  ? 'نزدیک انقضا'
                                  : batch.status === 'valid'
                                    ? 'معتبر'
                                    : 'بدون انقضا'
                        }}
                    </span>
                </div>
                <p
                    v-if="!batches.length"
                    class="py-10 text-center text-sm text-muted-foreground"
                >
                    Batchی ثبت نشده است.
                </p>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border bg-card">
            <div class="border-b px-5 py-4">
                <h2 class="text-sm font-bold">شماره‌های سریال</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-right text-sm">
                    <thead class="bg-muted/35 text-[11px]">
                        <tr>
                            <th class="px-5 py-3">سریال</th>
                            <th class="px-4 py-3">Batch</th>
                            <th class="px-4 py-3">انبار</th>
                            <th class="px-4 py-3">وضعیت</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="serial in serials" :key="serial.id">
                            <td class="px-5 py-3 font-mono text-xs">
                                {{ serial.serial_number }}
                            </td>
                            <td class="px-4 py-3 text-xs">
                                {{ serial.batch?.batch_number ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-xs">
                                {{ serial.warehouse?.name ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-xs">
                                {{ serial.status_label }}
                            </td>
                            <td class="px-5 py-3 text-left">
                                <Button
                                    v-if="serial.status === 'available'"
                                    variant="ghost"
                                    size="sm"
                                    class="text-red-600"
                                    @click="voidSerial(serial)"
                                >
                                    <CircleOff class="size-4" /> ابطال
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p
                v-if="!serials.length"
                class="py-10 text-center text-sm text-muted-foreground"
            >
                سریالی ثبت نشده است.
            </p>
        </section>
    </div>
</template>
