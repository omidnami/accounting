<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    CalendarDays,
    Check,
    CircleAlert,
    FileText,
    Printer,
    Trash2,
    Warehouse,
} from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    confirm as confirmPurchase,
    create as createPurchase,
    destroy as destroyPurchase,
    index as purchasesIndex,
} from '@/routes/purchases';

type Status =
    | 'draft'
    | 'confirmed'
    | 'partially_paid'
    | 'paid'
    | 'reversed'
    | 'cancelled';

const props = defineProps<{
    invoice: {
        id: number;
        invoice_number: string;
        invoice_date: string;
        due_date: string | null;
        status: Status;
        subtotal: string;
        discount_total: string;
        tax_total: string;
        total: string;
        notes: string | null;
        supplier: { name: string; code: string; phone: string | null };
        items: Array<{
            id: number;
            product_code: string | null;
            product_name: string;
            unit: string;
            quantity: string;
            unit_price: string;
            discount_amount: string;
            tax_rate: string;
            tax_amount: string;
            line_total: string;
        }>;
    };
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
const statusLabel: Record<Status, string> = {
    draft: 'پیش‌نویس',
    confirmed: 'تأییدشده',
    partially_paid: 'پرداخت جزئی',
    paid: 'تسویه‌شده',
    reversed: 'برگشتی',
    cancelled: 'لغوشده',
};
const fieldError = (key: string) =>
    (form.errors as Record<string, string>)[key];
const isDraft = computed(() => props.invoice.status === 'draft');

const confirm = () => {
    form.post(confirmPurchase(props.invoice.id).url, {
        preserveScroll: true,
    });
};
const remove = () => {
    if (window.confirm('پیش‌نویس فاکتور خرید حذف شود؟')) {
        router.delete(destroyPurchase(props.invoice.id).url);
    }
};
const printInvoice = () => {
    window.print();
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'خرید و تأمین', href: purchasesIndex() },
            { title: 'جزئیات خرید', href: '/purchases' },
        ],
    },
});
</script>

<template>
    <Head :title="`خرید ${invoice.invoice_number}`" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8 print:border-none print:bg-white"
        >
            <div
                class="flex flex-col justify-between gap-4 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <FileText class="size-5" />
                        <span class="text-xs font-bold">فاکتور خرید</span>
                        <span
                            class="rounded-full bg-muted px-2.5 py-1 text-[10px]"
                            >{{ statusLabel[invoice.status] }}</span
                        >
                    </div>
                    <h1 class="font-mono text-2xl font-black">
                        {{ invoice.invoice_number }}
                    </h1>
                    <p class="mt-2 flex items-center gap-2 text-xs">
                        <CalendarDays class="size-4" />
                        {{
                            date.format(
                                new Date(`${invoice.invoice_date}T00:00:00`),
                            )
                        }}
                    </p>
                </div>
                <div class="flex gap-2 print:hidden">
                    <Button variant="outline" @click="printInvoice">
                        <Printer class="size-4" /> چاپ
                    </Button>
                    <Button as-child variant="outline">
                        <Link :href="createPurchase()">خرید جدید</Link>
                    </Button>
                    <Button as-child variant="ghost">
                        <Link :href="purchasesIndex()">
                            <ArrowRight class="size-4" /> بازگشت
                        </Link>
                    </Button>
                </div>
            </div>
        </section>

        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
            <div class="space-y-6">
                <section class="rounded-2xl border bg-card p-5">
                    <div class="flex items-start gap-3">
                        <Building2 class="size-5 text-primary" />
                        <div>
                            <p class="text-[10px] text-muted-foreground">
                                تأمین‌کننده
                            </p>
                            <p class="mt-1 text-sm font-bold">
                                {{ invoice.supplier.name }}
                            </p>
                            <p class="mt-1 text-[10px] text-muted-foreground">
                                {{ invoice.supplier.code }} ·
                                {{ invoice.supplier.phone ?? 'بدون تلفن' }}
                            </p>
                        </div>
                    </div>
                </section>

                <section class="overflow-hidden rounded-2xl border bg-card">
                    <div class="border-b px-5 py-4">
                        <h2 class="text-sm font-bold">اقلام خرید</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[760px] text-right">
                            <thead class="bg-muted/35 text-[11px]">
                                <tr>
                                    <th class="px-5 py-3">کالا</th>
                                    <th class="px-3 py-3">مقدار</th>
                                    <th class="px-3 py-3">قیمت خرید</th>
                                    <th class="px-3 py-3">تخفیف</th>
                                    <th class="px-3 py-3">مالیات</th>
                                    <th class="px-5 py-3">جمع</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="item in invoice.items"
                                    :key="item.id"
                                >
                                    <td class="px-5 py-4">
                                        <p class="text-xs font-bold">
                                            {{ item.product_name }}
                                        </p>
                                        <p
                                            class="mt-1 text-[9px] text-muted-foreground"
                                        >
                                            {{ item.product_code }}
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

            <aside class="space-y-4 xl:sticky xl:top-6 print:hidden">
                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="text-sm font-bold">خلاصه مالی</h2>
                    <div class="mt-5 space-y-3 text-xs">
                        <div class="flex justify-between">
                            <span>جمع کالاها</span>
                            <span>{{
                                money.format(Number(invoice.subtotal))
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>تخفیف</span>
                            <span class="text-red-600"
                                >−
                                {{
                                    money.format(Number(invoice.discount_total))
                                }}</span
                            >
                        </div>
                        <div class="flex justify-between">
                            <span>مالیات</span>
                            <span>{{
                                money.format(Number(invoice.tax_total))
                            }}</span>
                        </div>
                        <div
                            class="flex justify-between border-t border-dashed pt-4 text-lg font-black"
                        >
                            <span>جمع نهایی</span>
                            <span>{{
                                money.format(Number(invoice.total))
                            }}</span>
                        </div>
                    </div>
                </section>

                <section
                    v-if="isDraft"
                    class="rounded-2xl border border-primary/15 bg-primary/[.045] p-5"
                >
                    <div class="flex items-center gap-2">
                        <Warehouse class="size-5 text-primary" />
                        <h2 class="text-sm font-bold">تأیید ورود به انبار</h2>
                    </div>
                    <select
                        v-model="form.warehouse_id"
                        class="mt-4 h-10 w-full rounded-xl border bg-background px-3 text-sm"
                    >
                        <option
                            v-for="warehouse in warehouses"
                            :key="warehouse.id"
                            :value="warehouse.id.toString()"
                        >
                            {{ warehouse.name }}
                        </option>
                    </select>
                    <InputError
                        class="mt-2"
                        :message="form.errors.warehouse_id"
                    />
                    <div
                        v-if="fieldError('purchase')"
                        class="mt-3 flex gap-2 rounded-xl bg-red-500/10 p-3 text-[10px] text-red-700"
                    >
                        <CircleAlert class="size-4 shrink-0" />
                        {{ fieldError('purchase') }}
                    </div>
                    <Button
                        class="mt-4 h-11 w-full rounded-xl"
                        :disabled="form.processing || !form.warehouse_id"
                        @click="confirm"
                    >
                        <Spinner v-if="form.processing" />
                        <Check v-else class="size-4" />
                        تأیید و ورود انبار
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        class="mt-2 h-9 w-full rounded-xl text-red-600"
                        @click="remove"
                    >
                        <Trash2 class="size-4" /> حذف پیش‌نویس
                    </Button>
                </section>
            </aside>
        </div>
    </div>
</template>
