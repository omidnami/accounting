<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarDays,
    Check,
    CircleAlert,
    CircleCheck,
    FileText,
    Printer,
    ShoppingCart,
    Trash2,
    UserRound,
    Warehouse,
} from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import {
    invoicePrintFormatMap,
    invoicePrintFormats,
    type InvoicePrintFormat,
} from '@/lib/printing';
import SalesInvoicePage from '@/pages/sales/SalesInvoicePage.vue';
import SalesReceipt from '@/pages/sales/SalesReceipt.vue';
import {
    confirm as confirmSaleRoute,
    create as createSale,
    destroy as destroySale,
    index as salesIndex,
} from '@/routes/sales';
import { create as createReceipt } from '@/routes/receipts';
import {
    create as createSalesReturn,
    show as showSalesReturn,
} from '@/routes/sales/returns';

type SaleStatus =
    | 'draft'
    | 'confirmed'
    | 'partially_paid'
    | 'paid'
    | 'reversed'
    | 'cancelled';

type Props = {
    can_confirm: boolean;
    can_receive: boolean;
    can_return: boolean;
    has_print_station: boolean;
    outstanding: string;
    seller: {
        name: string;
        branch_name: string;
        phone: string | null;
        email: string | null;
        national_id: string | null;
        tax_id: string | null;
        address: string | null;
    };
    print_settings: {
        format: InvoicePrintFormat;
        paper_width_mm: 58 | 80;
        copies: number;
        header_note: string | null;
    };
    returns: Array<{
        id: number;
        invoice_number: string;
        invoice_date: string;
        status: SaleStatus;
        total: string;
    }>;
    invoice: {
        id: number;
        invoice_number: string;
        invoice_date: string;
        due_date: string | null;
        status: SaleStatus;
        subtotal: string;
        discount_total: string;
        tax_total: string;
        total: string;
        notes: string | null;
        customer: {
            id: number;
            name: string;
            code: string;
            phone: string | null;
            credit_limit: string;
        };
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
    warehouses: Array<{
        id: number;
        name: string;
        is_default: boolean;
    }>;
};

const props = defineProps<Props>();
const form = useForm({
    warehouse_id:
        props.warehouses
            .find((warehouse) => warehouse.is_default)
            ?.id.toString() ??
        props.warehouses[0]?.id.toString() ??
        '',
});
const money = new Intl.NumberFormat('fa-IR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});
const quantity = new Intl.NumberFormat('fa-IR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 3,
});
const date = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
});
const statusMeta: Record<SaleStatus, { label: string; className: string }> = {
    draft: {
        label: 'پیش‌نویس',
        className: 'bg-slate-500/10 text-slate-600 dark:text-slate-300',
    },
    confirmed: {
        label: 'تأییدشده',
        className: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
    },
    partially_paid: {
        label: 'پرداخت جزئی',
        className: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    },
    paid: {
        label: 'تسویه‌شده',
        className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    },
    reversed: {
        label: 'برگشت‌خورده',
        className: 'bg-red-500/10 text-red-700 dark:text-red-300',
    },
    cancelled: {
        label: 'لغوشده',
        className: 'bg-muted text-muted-foreground',
    },
};
const isDraft = computed(() => props.invoice.status === 'draft');
const printDialogOpen = ref(false);
const selectedPrintFormat = ref<InvoicePrintFormat>(
    props.print_settings.format,
);
const selectedCopies = ref(props.print_settings.copies);
const selectedPrintOption = computed(
    () => invoicePrintFormatMap[selectedPrintFormat.value],
);
const printCopies = computed(() =>
    Array.from(
        { length: Math.max(1, Math.min(5, selectedCopies.value)) },
        (_, index) => index + 1,
    ),
);
const canReceive = computed(
    () => props.can_receive && !isDraft.value && Number(props.outstanding) > 0,
);
const fieldError = (key: string) =>
    (form.errors as Record<string, string>)[key];

const confirmSale = () => {
    form.post(confirmSaleRoute(props.invoice.id).url, {
        preserveScroll: true,
    });
};

const deleteDraft = () => {
    if (!window.confirm('پیش‌نویس فاکتور حذف شود؟')) {
        return;
    }

    router.delete(destroySale(props.invoice.id).url, {
        preserveScroll: true,
    });
};

const openPrintDialog = () => {
    selectedPrintFormat.value = props.print_settings.format;
    selectedCopies.value = props.print_settings.copies;
    printDialogOpen.value = true;
};
const printInvoice = async () => {
    printDialogOpen.value = false;
    await nextTick();
    window.print();
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'فروش و صندوق',
                href: salesIndex(),
            },
            {
                title: 'جزئیات فاکتور',
                href: '/sales',
            },
        ],
    },
});
</script>

<template>
    <Head :title="`فاکتور ${invoice.invoice_number}`" />

    <Dialog v-model:open="printDialogOpen">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>آماده‌سازی چاپ فاکتور</DialogTitle>
                <DialogDescription>
                    قالب و تعداد نسخه را برای این چاپ انتخاب کنید. انتخاب چاپگر
                    در پنجرهٔ Print سیستم‌عامل انجام می‌شود.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-3 sm:grid-cols-2">
                <button
                    v-for="format in invoicePrintFormats"
                    :key="format.value"
                    type="button"
                    class="rounded-xl border p-3 text-right transition"
                    :class="
                        selectedPrintFormat === format.value
                            ? 'border-primary bg-primary/5 ring-2 ring-primary/15'
                            : 'hover:border-primary/40'
                    "
                    @click="selectedPrintFormat = format.value"
                >
                    <span class="block text-xs font-bold">{{
                        format.label
                    }}</span>
                    <span
                        class="mt-1 block text-[10px] leading-5 text-muted-foreground"
                    >
                        {{ format.description }}
                    </span>
                </button>
            </div>

            <label class="grid gap-2">
                <span class="text-xs font-semibold">تعداد نسخه</span>
                <select
                    v-model="selectedCopies"
                    class="h-10 rounded-xl border bg-background px-3 text-sm"
                >
                    <option
                        v-for="copy in [1, 2, 3, 4, 5]"
                        :key="copy"
                        :value="copy"
                    >
                        {{ copy }} نسخه
                    </option>
                </select>
            </label>

            <div
                class="rounded-xl border border-primary/15 bg-primary/5 p-3 text-xs"
            >
                <p class="font-bold">{{ selectedPrintOption.label }}</p>
                <p class="mt-1 text-muted-foreground">
                    {{ selectedCopies }} نسخه آماده چاپ می‌شود.
                </p>
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    class="rounded-xl"
                    @click="printDialogOpen = false"
                >
                    انصراف
                </Button>
                <Button type="button" class="rounded-xl" @click="printInvoice">
                    <Printer class="size-4" />
                    ادامه و چاپ
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <div class="hidden print:block">
        <template v-if="selectedPrintOption.kind === 'thermal'">
            <SalesReceipt
                v-for="copy in printCopies"
                :key="copy"
                :seller="seller"
                :invoice="invoice"
                :paper-width="selectedPrintOption.paperWidth ?? 80"
                :status-label="statusMeta[invoice.status].label"
                :header-note="print_settings.header_note"
            />
        </template>
        <template v-else>
            <SalesInvoicePage
                v-for="copy in printCopies"
                :key="copy"
                :seller="seller"
                :invoice="invoice"
                :format="selectedPrintFormat === 'a5' ? 'a5' : 'a4'"
                :status-label="statusMeta[invoice.status].label"
                :header-note="print_settings.header_note"
            />
        </template>
    </div>

    <div
        class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8 print:hidden"
    >
        <section
            class="relative overflow-hidden rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8 print:border-none print:bg-white"
        >
            <div
                class="relative flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div class="text-center print:mb-2">
                    <p class="text-base font-black">{{ seller.name }}</p>
                    <p class="mt-1 text-[10px] text-muted-foreground">
                        شعبه {{ seller.branch_name }}
                        <template v-if="seller.phone">
                            · {{ seller.phone }}
                        </template>
                    </p>
                    <p
                        v-if="seller.national_id || seller.tax_id"
                        class="mt-1 text-[9px] text-muted-foreground"
                    >
                        <template v-if="seller.national_id">
                            شناسه ملی: {{ seller.national_id }}
                        </template>
                        <template v-if="seller.tax_id">
                            · شناسه مالیاتی: {{ seller.tax_id }}
                        </template>
                    </p>
                    <p
                        v-if="seller.address"
                        class="mx-auto mt-1 max-w-md text-[9px] leading-5 text-muted-foreground"
                    >
                        {{ seller.address }}
                    </p>
                    <div
                        class="mt-3 mb-3 flex flex-wrap items-center justify-center gap-2 text-primary print:mb-1"
                    >
                        <FileText class="size-5" />
                        <span class="text-xs font-bold">فاکتور فروش</span>
                        <span
                            class="rounded-full px-2.5 py-1 text-[10px] font-semibold"
                            :class="statusMeta[invoice.status].className"
                        >
                            {{ statusMeta[invoice.status].label }}
                        </span>
                    </div>
                    <h1
                        dir="ltr"
                        class="text-right font-mono text-2xl font-black tracking-tight sm:text-3xl"
                    >
                        {{ invoice.invoice_number }}
                    </h1>
                    <p
                        class="mt-2 flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <CalendarDays class="size-4" />
                        {{
                            date.format(
                                new Date(`${invoice.invoice_date}T00:00:00`),
                            )
                        }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2 print:hidden">
                    <Button
                        variant="outline"
                        class="rounded-xl"
                        @click="openPrintDialog"
                    >
                        <Printer class="size-4" />
                        چاپ
                    </Button>
                    <Button
                        v-if="has_print_station && invoice.status !== 'draft'"
                        variant="outline"
                        class="rounded-xl"
                        @click="
                            router.post(
                                `/sales/${invoice.id}/print-agent`,
                                {},
                                { preserveScroll: true },
                            )
                        "
                    >
                        <Printer class="size-4" />
                        چاپ خودکار
                    </Button>
                    <Button as-child variant="outline" class="rounded-xl">
                        <Link :href="createSale()">
                            <ShoppingCart class="size-4" />
                            فروش جدید
                        </Link>
                    </Button>
                    <Button as-child variant="ghost" class="rounded-xl">
                        <Link :href="salesIndex()">
                            <ArrowRight class="size-4" />
                            بازگشت
                        </Link>
                    </Button>
                </div>
            </div>
        </section>

        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
            <div class="space-y-6">
                <section
                    class="grid gap-4 rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:grid-cols-2"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                        >
                            <UserRound class="size-5" />
                        </span>
                        <div>
                            <p class="text-[10px] text-muted-foreground">
                                مشتری
                            </p>
                            <p class="mt-1 text-sm font-bold">
                                {{ invoice.customer.name }}
                            </p>
                            <p class="mt-1 text-[10px] text-muted-foreground">
                                کد {{ invoice.customer.code }} ·
                                {{ invoice.customer.phone ?? 'بدون تلفن' }}
                            </p>
                        </div>
                    </div>
                    <div class="thermal-hide flex items-start gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600"
                        >
                            <CircleCheck class="size-5" />
                        </span>
                        <div>
                            <p class="text-[10px] text-muted-foreground">
                                سقف اعتبار مشتری
                            </p>
                            <p class="mt-1 text-sm font-bold">
                                {{
                                    money.format(
                                        Number(invoice.customer.credit_limit),
                                    )
                                }}
                                <small class="text-[9px]">ریال</small>
                            </p>
                            <p class="mt-1 text-[10px] text-muted-foreground">
                                سررسید:
                                {{
                                    invoice.due_date
                                        ? date.format(
                                              new Date(
                                                  `${invoice.due_date}T00:00:00`,
                                              ),
                                          )
                                        : 'ندارد'
                                }}
                            </p>
                        </div>
                    </div>
                </section>

                <section
                    class="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
                >
                    <div class="border-b border-border/70 px-5 py-4">
                        <h2 class="text-sm font-bold">اقلام فاکتور</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[760px] text-right">
                            <thead
                                class="bg-muted/35 text-[11px] text-muted-foreground"
                            >
                                <tr>
                                    <th class="px-5 py-3 font-semibold">
                                        کالا
                                    </th>
                                    <th class="px-3 py-3 font-semibold">
                                        مقدار
                                    </th>
                                    <th class="px-3 py-3 font-semibold">
                                        قیمت واحد
                                    </th>
                                    <th
                                        class="thermal-hide px-3 py-3 font-semibold"
                                    >
                                        تخفیف
                                    </th>
                                    <th
                                        class="thermal-hide px-3 py-3 font-semibold"
                                    >
                                        مالیات
                                    </th>
                                    <th class="px-5 py-3 font-semibold">
                                        جمع ردیف
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/60">
                                <tr
                                    v-for="item in invoice.items"
                                    :key="item.id"
                                >
                                    <td class="px-5 py-4">
                                        <p class="text-xs font-bold">
                                            {{ item.product_name }}
                                        </p>
                                        <p
                                            dir="ltr"
                                            class="thermal-hide mt-1 text-right font-mono text-[9px] text-muted-foreground"
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
                                    <td
                                        class="thermal-hide px-3 py-4 text-xs text-red-600"
                                    >
                                        {{
                                            money.format(
                                                Number(item.discount_amount),
                                            )
                                        }}
                                    </td>
                                    <td class="thermal-hide px-3 py-4 text-xs">
                                        {{
                                            money.format(
                                                Number(item.tax_amount),
                                            )
                                        }}
                                        <span
                                            class="block text-[9px] text-muted-foreground"
                                            >{{ item.tax_rate }}٪</span
                                        >
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

                <section
                    v-if="invoice.notes"
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <p class="text-[10px] font-semibold text-muted-foreground">
                        یادداشت
                    </p>
                    <p class="mt-2 text-xs leading-7">{{ invoice.notes }}</p>
                </section>

                <section
                    class="hidden rounded-2xl border border-border/70 bg-card p-4 shadow-sm print:block"
                >
                    <div class="space-y-2 text-xs">
                        <div class="flex justify-between">
                            <span>جمع کالاها</span>
                            <span>{{
                                money.format(Number(invoice.subtotal))
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>تخفیف</span>
                            <span>{{
                                money.format(Number(invoice.discount_total))
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>مالیات</span>
                            <span>{{
                                money.format(Number(invoice.tax_total))
                            }}</span>
                        </div>
                        <div
                            class="thermal-total flex items-end justify-between border-t border-dashed pt-2"
                        >
                            <span class="font-bold">جمع نهایی</span>
                            <span class="text-lg font-black">
                                {{ money.format(Number(invoice.total)) }}
                                <small class="text-[8px] font-medium"
                                    >ریال</small
                                >
                            </span>
                        </div>
                    </div>
                </section>

                <section
                    v-if="returns.length"
                    class="thermal-hide overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
                >
                    <div class="border-b border-border/70 px-5 py-4">
                        <h2 class="text-sm font-bold">برگشت‌های این فاکتور</h2>
                    </div>
                    <div class="divide-y">
                        <Link
                            v-for="returnInvoice in returns"
                            :key="returnInvoice.id"
                            :href="showSalesReturn(returnInvoice.id)"
                            class="flex items-center justify-between gap-4 px-5 py-3 text-xs transition hover:bg-muted/30"
                        >
                            <span>
                                <span class="block font-mono font-bold">
                                    {{ returnInvoice.invoice_number }}
                                </span>
                                <span
                                    class="mt-1 block text-[9px] text-muted-foreground"
                                >
                                    {{ statusMeta[returnInvoice.status].label }}
                                </span>
                            </span>
                            <span class="font-bold">
                                {{ money.format(Number(returnInvoice.total)) }}
                            </span>
                        </Link>
                    </div>
                </section>
            </div>

            <aside class="space-y-4 xl:sticky xl:top-6 print:hidden">
                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <h2 class="text-sm font-bold">خلاصه مالی</h2>
                    <div class="mt-5 space-y-3 text-xs">
                        <div class="flex justify-between">
                            <span class="text-muted-foreground"
                                >جمع کالاها</span
                            >
                            <span>{{
                                money.format(Number(invoice.subtotal))
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">تخفیف</span>
                            <span class="text-red-600"
                                >−
                                {{
                                    money.format(Number(invoice.discount_total))
                                }}</span
                            >
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">مالیات</span>
                            <span>{{
                                money.format(Number(invoice.tax_total))
                            }}</span>
                        </div>
                        <div
                            class="flex items-end justify-between border-t border-dashed pt-4"
                        >
                            <span class="font-semibold">جمع نهایی</span>
                            <span class="text-xl font-black"
                                >{{ money.format(Number(invoice.total)) }}
                                <small class="text-[9px] font-medium"
                                    >ریال</small
                                ></span
                            >
                        </div>
                    </div>
                </section>

                <section
                    v-if="isDraft && can_confirm"
                    class="rounded-2xl border border-primary/15 bg-primary/[.045] p-5"
                >
                    <div class="flex items-center gap-2">
                        <Warehouse class="size-5 text-primary" />
                        <h2 class="text-sm font-bold">تأیید نهایی فروش</h2>
                    </div>
                    <p class="mt-2 text-[10px] leading-6 text-muted-foreground">
                        با تأیید، موجودی انبار خارج، بهای تمام‌شدهٔ کالای
                        فروش‌رفته ثبت و سند حساب‌های دریافتنی، فروش، مالیات و
                        موجودی ایجاد می‌شود.
                    </p>
                    <label class="mt-4 grid gap-2">
                        <span class="text-[10px] font-semibold"
                            >انبار خروج</span
                        >
                        <select
                            v-model="form.warehouse_id"
                            class="h-10 rounded-xl border border-input bg-background px-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
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
                        v-if="fieldError('sale')"
                        class="mt-3 flex items-start gap-2 rounded-xl bg-red-500/10 px-3 py-2 text-[10px] leading-5 text-red-700 dark:text-red-300"
                    >
                        <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
                        {{ fieldError('sale') }}
                    </div>
                    <Button
                        class="mt-4 h-11 w-full rounded-xl"
                        :disabled="form.processing || !form.warehouse_id"
                        @click="confirmSale"
                    >
                        <Spinner v-if="form.processing" />
                        <Check v-else class="size-4" />
                        تأیید و ثبت قطعی فروش
                    </Button>
                </section>

                <section
                    v-if="canReceive"
                    class="rounded-2xl border border-emerald-500/20 bg-emerald-500/[.055] p-5"
                >
                    <div class="flex items-center gap-2">
                        <CircleCheck class="size-5 text-emerald-600" />
                        <h2 class="text-sm font-bold">مانده فاکتور</h2>
                    </div>
                    <p class="mt-3 text-xl font-black">
                        {{ money.format(Number(outstanding)) }}
                        <small class="text-[9px] font-medium">ریال</small>
                    </p>
                    <Button as-child class="mt-4 h-10 w-full rounded-xl">
                        <Link
                            :href="
                                createReceipt.url({
                                    query: {
                                        customer_id: invoice.customer.id,
                                        invoice_id: invoice.id,
                                    },
                                })
                            "
                        >
                            ثبت دریافت وجه
                        </Link>
                    </Button>
                </section>

                <section
                    v-if="can_return"
                    class="rounded-2xl border border-amber-500/20 bg-amber-500/[.055] p-5"
                >
                    <div class="flex items-center gap-2">
                        <CircleAlert class="size-5 text-amber-600" />
                        <h2 class="text-sm font-bold">برگشت از فروش</h2>
                    </div>
                    <p class="mt-2 text-[10px] leading-6 text-muted-foreground">
                        برای اقلام این فاکتور مقدار قابل برگشت وجود دارد. ثبت
                        برگشت با ورود موجودی و سند اصلاحی انجام می‌شود.
                    </p>
                    <Button
                        as-child
                        variant="outline"
                        class="mt-4 h-10 w-full rounded-xl"
                    >
                        <Link :href="createSalesReturn(invoice.id)">
                            ثبت برگشت فروش
                        </Link>
                    </Button>
                </section>

                <section
                    v-else-if="isDraft && !can_confirm"
                    class="rounded-2xl border border-amber-500/20 bg-amber-500/[.055] p-4"
                >
                    <div class="flex items-start gap-2.5">
                        <CircleAlert
                            class="mt-0.5 size-4 shrink-0 text-amber-600"
                        />
                        <p class="text-[10px] leading-6 text-muted-foreground">
                            این پیش‌نویس آماده بررسی است. تأیید نهایی، خروج
                            انبار و ثبت سند فقط توسط مدیر یا حسابدار انجام
                            می‌شود.
                        </p>
                    </div>
                </section>

                <section
                    v-if="isDraft"
                    class="rounded-2xl border border-red-500/15 bg-card p-4"
                >
                    <Button
                        type="button"
                        variant="ghost"
                        class="h-9 w-full rounded-xl text-red-600"
                        :disabled="form.processing"
                        @click="deleteDraft"
                    >
                        <Trash2 class="size-4" />
                        حذف پیش‌نویس
                    </Button>
                </section>

                <section
                    v-else
                    class="rounded-2xl border border-emerald-500/20 bg-emerald-500/[.055] p-4"
                >
                    <div class="flex items-start gap-2.5">
                        <CircleCheck
                            class="mt-0.5 size-4 shrink-0 text-emerald-600"
                        />
                        <p class="text-[10px] leading-6 text-muted-foreground">
                            این فاکتور نهایی شده و اقلام، موجودی و سند آن قابل
                            ویرایش نیستند. اصلاح باید با سند برگشت یا اصلاحی
                            انجام شود.
                        </p>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</template>
