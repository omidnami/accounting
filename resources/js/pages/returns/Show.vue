<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarDays,
    Check,
    CircleAlert,
    PencilLine,
    PackageMinus,
    Trash2,
    Warehouse,
} from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    show as showPurchase,
    index as purchasesIndex,
} from '@/routes/purchases';
import * as purchaseReturns from '@/routes/purchases/returns';
import { index as salesIndex, show as showSale } from '@/routes/sales';
import * as salesReturns from '@/routes/sales/returns';

type Kind = 'sales' | 'purchase';
type Status =
    | 'draft'
    | 'confirmed'
    | 'partially_paid'
    | 'paid'
    | 'reversed'
    | 'cancelled';
type ReturnInvoice = {
    id: number;
    invoice_number: string;
    invoice_date: string;
    status: Status;
    subtotal: string;
    discount_total: string;
    tax_total: string;
    total: string;
    notes: string | null;
    party: {
        name: string | null;
        code: string | null;
        phone: string | null;
    };
    items: Array<{
        id: number;
        product_code: string | null;
        product_name: string;
        unit: string;
        quantity: string;
        unit_price: string;
        discount_amount: string;
        tax_amount: string;
        line_total: string;
    }>;
};

const props = defineProps<{
    kind: Kind;
    return_invoice: ReturnInvoice;
    parent: {
        id: number;
        invoice_number: string;
        invoice_date: string;
        total: string;
        status: string;
    } | null;
    warehouses: Array<{ id: number; name: string; is_default: boolean }>;
}>();

const form = useForm({
    warehouse_id:
        props.warehouses
            .find((warehouse) => warehouse.is_default)
            ?.id.toString() ??
        props.warehouses[0]?.id.toString() ??
        '',
});
const money = new Intl.NumberFormat('fa-IR');
const quantity = new Intl.NumberFormat('fa-IR', {
    maximumFractionDigits: 3,
});
const date = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
});
const isSales = computed(() => props.kind === 'sales');
const title = computed(() => (isSales.value ? 'برگشت فروش' : 'برگشت خرید'));
const isDraft = computed(() => props.return_invoice.status === 'draft');
const statusLabel = computed(() => {
    const labels: Record<Status, string> = {
        draft: 'پیش‌نویس',
        confirmed: 'تأییدشده',
        partially_paid: 'تسویه جزئی',
        paid: 'تسویه‌شده',
        reversed: 'برگشت‌خورده',
        cancelled: 'لغوشده',
    };

    return labels[props.return_invoice.status];
});
const parentUrl = computed(() =>
    props.parent
        ? isSales.value
            ? showSale(props.parent.id)
            : showPurchase(props.parent.id)
        : null,
);
const indexUrl = computed(() =>
    isSales.value ? salesIndex() : purchasesIndex(),
);
const confirmUrl = computed(() =>
    isSales.value
        ? salesReturns.confirm(props.return_invoice.id).url
        : purchaseReturns.confirm(props.return_invoice.id).url,
);
const destroyUrl = computed(() =>
    isSales.value
        ? salesReturns.destroy(props.return_invoice.id).url
        : purchaseReturns.destroy(props.return_invoice.id).url,
);
const editUrl = computed(() =>
    isSales.value
        ? salesReturns.edit(props.return_invoice.id).url
        : purchaseReturns.edit(props.return_invoice.id).url,
);
const fieldError = (key: string) =>
    (form.errors as Record<string, string>)[key];

const confirm = () => {
    form.post(confirmUrl.value, { preserveScroll: true });
};
const deleteDraft = () => {
    if (!window.confirm('پیش‌نویس برگشت حذف شود؟')) {
        return;
    }

    router.delete(destroyUrl.value, { preserveScroll: true });
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'داشبورد', href: '/' },
            { title: 'جزئیات برگشت', href: '#' },
        ],
    },
});
</script>

<template>
    <Head :title="`${title} ${return_invoice.invoice_number}`" />

    <div class="mx-auto w-full max-w-6xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2">
                        <PackageMinus class="size-5 text-primary" />
                        <span class="text-xs font-bold">{{ title }}</span>
                        <span
                            class="rounded-full bg-primary/10 px-2.5 py-1 text-[10px] font-semibold text-primary"
                        >
                            {{ statusLabel }}
                        </span>
                    </div>
                    <h1 class="font-mono text-2xl font-black">
                        {{ return_invoice.invoice_number }}
                    </h1>
                    <p
                        class="mt-2 flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <CalendarDays class="size-4" />
                        {{
                            date.format(
                                new Date(
                                    `${return_invoice.invoice_date}T00:00:00`,
                                ),
                            )
                        }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <Button as-child variant="outline" class="rounded-xl">
                        <Link :href="indexUrl">
                            <ArrowRight class="size-4" />
                            فهرست
                        </Link>
                    </Button>
                    <Button
                        v-if="parentUrl"
                        as-child
                        variant="outline"
                        class="rounded-xl"
                    >
                        <Link :href="parentUrl">فاکتور اصلی</Link>
                    </Button>
                </div>
            </div>
        </section>

        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_330px]">
            <div class="space-y-5">
                <section
                    class="grid gap-4 rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:grid-cols-2"
                >
                    <div>
                        <p class="text-[10px] text-muted-foreground">
                            {{ isSales ? 'مشتری' : 'تأمین‌کننده' }}
                        </p>
                        <p class="mt-1 text-sm font-bold">
                            {{ return_invoice.party.name ?? 'نامشخص' }}
                        </p>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            {{ return_invoice.party.code ?? 'بدون کد' }} ·
                            {{ return_invoice.party.phone ?? 'بدون تلفن' }}
                        </p>
                    </div>
                    <div v-if="parent">
                        <p class="text-[10px] text-muted-foreground">
                            فاکتور اصلی
                        </p>
                        <Link
                            :href="parentUrl!"
                            class="mt-1 block font-mono text-sm font-bold text-primary hover:underline"
                        >
                            {{ parent.invoice_number }}
                        </Link>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            جمع {{ money.format(Number(parent.total)) }} ریال
                        </p>
                    </div>
                </section>

                <section
                    class="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
                >
                    <div class="border-b border-border/70 px-5 py-4">
                        <h2 class="text-sm font-bold">اقلام برگشتی</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[700px] text-right">
                            <thead class="bg-muted/35 text-[11px]">
                                <tr>
                                    <th class="px-5 py-3">کالا</th>
                                    <th class="px-3 py-3">مقدار</th>
                                    <th class="px-3 py-3">قیمت واحد</th>
                                    <th class="px-3 py-3">تخفیف</th>
                                    <th class="px-3 py-3">مالیات</th>
                                    <th class="px-5 py-3">جمع</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="item in return_invoice.items"
                                    :key="item.id"
                                >
                                    <td class="px-5 py-4">
                                        <p class="text-xs font-bold">
                                            {{ item.product_name }}
                                        </p>
                                        <p
                                            class="mt-1 text-[9px] text-muted-foreground"
                                        >
                                            {{ item.product_code ?? '—' }}
                                        </p>
                                    </td>
                                    <td class="px-3 py-4 text-xs">
                                        {{
                                            quantity.format(
                                                Number(item.quantity),
                                            )
                                        }}
                                        {{ item.unit }}
                                    </td>
                                    <td class="px-3 py-4 text-xs">
                                        {{
                                            money.format(
                                                Number(item.unit_price),
                                            )
                                        }}
                                    </td>
                                    <td class="px-3 py-4 text-xs">
                                        {{
                                            money.format(
                                                Number(item.discount_amount),
                                            )
                                        }}
                                    </td>
                                    <td class="px-3 py-4 text-xs">
                                        {{
                                            money.format(
                                                Number(item.tax_amount),
                                            )
                                        }}
                                    </td>
                                    <td class="px-5 py-4 text-xs font-bold">
                                        {{
                                            money.format(
                                                Number(item.line_total),
                                            )
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <aside class="space-y-4 xl:sticky xl:top-6">
                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <h2 class="text-sm font-bold">خلاصه مالی</h2>
                    <div class="mt-5 space-y-3 text-xs">
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">جمع اقلام</span>
                            <span>{{
                                money.format(Number(return_invoice.subtotal))
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">تخفیف</span>
                            <span>{{
                                money.format(
                                    Number(return_invoice.discount_total),
                                )
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">مالیات</span>
                            <span>{{
                                money.format(Number(return_invoice.tax_total))
                            }}</span>
                        </div>
                        <div
                            class="flex justify-between border-t border-dashed pt-4 text-base font-black"
                        >
                            <span>جمع برگشت</span>
                            <span
                                >{{
                                    money.format(Number(return_invoice.total))
                                }}
                                ریال</span
                            >
                        </div>
                    </div>
                </section>

                <section
                    v-if="isDraft"
                    class="rounded-2xl border border-primary/15 bg-primary/[.045] p-5"
                >
                    <div class="flex items-center gap-2">
                        <Warehouse class="size-5 text-primary" />
                        <h2 class="text-sm font-bold">تأیید نهایی برگشت</h2>
                    </div>
                    <p class="mt-2 text-[10px] leading-6 text-muted-foreground">
                        با تأیید، موجودی و سند حسابداری مرجوعی به‌صورت اتمیک ثبت
                        می‌شود.
                    </p>
                    <label class="mt-4 grid gap-2">
                        <span class="text-[10px] font-semibold">انبار</span>
                        <select
                            v-model="form.warehouse_id"
                            class="h-10 rounded-xl border border-input bg-background px-3 text-sm"
                        >
                            <option
                                v-for="warehouse in warehouses"
                                :key="warehouse.id"
                                :value="warehouse.id.toString()"
                            >
                                {{ warehouse.name }}
                            </option>
                        </select>
                        <InputError :message="form.errors.warehouse_id" />
                    </label>
                    <div
                        v-if="fieldError('return')"
                        class="mt-3 flex items-start gap-2 rounded-xl bg-red-500/10 px-3 py-2 text-[10px] leading-5 text-red-700 dark:text-red-300"
                    >
                        <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
                        {{ fieldError('return') }}
                    </div>
                    <Button
                        class="mt-4 h-11 w-full rounded-xl"
                        :disabled="form.processing || !form.warehouse_id"
                        @click="confirm"
                    >
                        <Spinner v-if="form.processing" />
                        <Check v-else class="size-4" />
                        تأیید و ثبت برگشت
                    </Button>
                    <Button
                        v-if="isDraft"
                        as-child
                        variant="outline"
                        class="rounded-xl"
                    >
                        <Link :href="editUrl">
                            <PencilLine class="size-4" />
                            ویرایش پیش‌نویس
                        </Link>
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        class="mt-2 h-9 w-full rounded-xl text-red-600"
                        :disabled="form.processing"
                        @click="deleteDraft"
                    >
                        <Trash2 class="size-4" />
                        حذف پیش‌نویس
                    </Button>
                </section>

                <section
                    v-if="return_invoice.notes"
                    class="rounded-2xl border border-border/70 bg-card p-4"
                >
                    <p class="text-[10px] font-semibold text-muted-foreground">
                        یادداشت
                    </p>
                    <p class="mt-2 text-xs leading-7">
                        {{ return_invoice.notes }}
                    </p>
                </section>
            </aside>
        </div>
    </div>
</template>
