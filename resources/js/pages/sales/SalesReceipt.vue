<script setup lang="ts">
import { computed } from 'vue';

type ReceiptInvoice = {
    invoice_number: string;
    invoice_date: string;
    due_date: string | null;
    status: string;
    subtotal: string;
    discount_total: string;
    tax_total: string;
    total: string;
    notes: string | null;
    customer: {
        name: string;
        code: string;
        phone: string | null;
    };
    items: Array<{
        id: number;
        product_code: string | null;
        product_name: string;
        unit: string;
        quantity: string;
        unit_price: string;
        line_total: string;
    }>;
};

const props = defineProps<{
    seller: {
        name: string;
        branch_name: string;
        phone: string | null;
        email: string | null;
        national_id: string | null;
        tax_id: string | null;
        address: string | null;
    };
    invoice: ReceiptInvoice;
    paperWidth: 58 | 80;
    statusLabel: string;
}>();

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
    timeZone: 'UTC',
});
const formatDate = (value: string) =>
    date.format(new Date(`${value}T12:00:00Z`));
const identity = computed(() =>
    [
        props.seller.national_id
            ? `شناسه ملی: ${props.seller.national_id}`
            : null,
        props.seller.tax_id ? `شناسه مالیاتی: ${props.seller.tax_id}` : null,
    ]
        .filter(Boolean)
        .join(' · '),
);
</script>

<template>
    <article class="thermal-receipt" :data-paper-width="paperWidth" dir="rtl">
        <header class="text-center">
            <h2 class="text-base font-black">{{ seller.name }}</h2>
            <p class="mt-1 text-[9px]">
                شعبه {{ seller.branch_name }}
                <template v-if="seller.phone"> · {{ seller.phone }}</template>
            </p>
            <p v-if="identity" class="mt-1 text-[8px]">{{ identity }}</p>
            <p v-if="seller.address" class="mt-1 text-[8px] leading-4">
                {{ seller.address }}
            </p>
        </header>

        <div class="my-3 border-y border-dashed py-2 text-[9px]">
            <div class="flex justify-between gap-2">
                <span>فاکتور فروش</span>
                <strong dir="ltr">{{ invoice.invoice_number }}</strong>
            </div>
            <div class="mt-1 flex justify-between gap-2">
                <span>{{ formatDate(invoice.invoice_date) }}</span>
                <span>{{ statusLabel }}</span>
            </div>
            <div
                v-if="invoice.due_date"
                class="mt-1 flex justify-between gap-2"
            >
                <span>سررسید</span>
                <span>{{ formatDate(invoice.due_date) }}</span>
            </div>
        </div>

        <section class="text-[9px]">
            <p class="font-bold">مشتری: {{ invoice.customer.name }}</p>
            <p class="mt-1">
                کد {{ invoice.customer.code }}
                <template v-if="invoice.customer.phone">
                    · {{ invoice.customer.phone }}
                </template>
            </p>
        </section>

        <table class="my-3 w-full text-right">
            <thead>
                <tr class="border-y">
                    <th class="py-1 text-right">کالا</th>
                    <th class="py-1 text-center">تعداد</th>
                    <th class="py-1 text-left">جمع</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="item in invoice.items"
                    :key="item.id"
                    class="border-b"
                >
                    <td class="py-1.5 pl-1">
                        <span class="block font-semibold">
                            {{ item.product_name }}
                        </span>
                        <span class="block text-[7px]">
                            {{ money.format(Number(item.unit_price)) }} ریال
                        </span>
                    </td>
                    <td class="px-1 py-1.5 text-center whitespace-nowrap">
                        {{ quantity.format(Number(item.quantity)) }}
                        {{ item.unit }}
                    </td>
                    <td
                        class="py-1.5 pr-1 text-left font-semibold whitespace-nowrap"
                    >
                        {{ money.format(Number(item.line_total)) }}
                    </td>
                </tr>
            </tbody>
        </table>

        <section class="space-y-1 text-[9px]">
            <div class="flex justify-between">
                <span>جمع کالاها</span>
                <span>{{ money.format(Number(invoice.subtotal)) }}</span>
            </div>
            <div class="flex justify-between">
                <span>تخفیف</span>
                <span>{{ money.format(Number(invoice.discount_total)) }}</span>
            </div>
            <div class="flex justify-between">
                <span>مالیات</span>
                <span>{{ money.format(Number(invoice.tax_total)) }}</span>
            </div>
            <div
                class="mt-2 flex items-end justify-between border-t border-dashed pt-2"
            >
                <strong>جمع نهایی</strong>
                <strong class="text-sm">
                    {{ money.format(Number(invoice.total)) }}
                    <small class="text-[7px]">ریال</small>
                </strong>
            </div>
        </section>

        <section
            v-if="invoice.notes"
            class="mt-3 border-t border-dashed pt-2 text-[8px] leading-4"
        >
            <strong>یادداشت:</strong>
            {{ invoice.notes }}
        </section>

        <footer
            v-if="seller.email"
            class="mt-3 border-t border-dashed pt-2 text-center text-[7px]"
        >
            {{ seller.email }}
        </footer>
    </article>
</template>
