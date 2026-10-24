<script setup lang="ts">
type InvoicePageData = {
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
        discount_amount: string;
        tax_rate: string;
        tax_amount: string;
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
    invoice: InvoicePageData;
    format: 'a5' | 'a4';
    statusLabel: string;
    headerNote: string | null;
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
</script>

<template>
    <article class="invoice-page" :data-invoice-format="format" dir="rtl">
        <header class="invoice-page-header">
            <div>
                <h2>{{ seller.name }}</h2>
                <p>شعبه {{ seller.branch_name }}</p>
                <p v-if="seller.address">{{ seller.address }}</p>
                <p v-if="seller.phone || seller.email">
                    <template v-if="seller.phone">{{ seller.phone }}</template>
                    <template v-if="seller.phone && seller.email"> · </template>
                    <template v-if="seller.email">{{ seller.email }}</template>
                </p>
            </div>
            <div class="text-left">
                <h1>فاکتور فروش</h1>
                <p>
                    <strong dir="ltr">{{ invoice.invoice_number }}</strong>
                </p>
                <p>{{ formatDate(invoice.invoice_date) }}</p>
                <p>{{ statusLabel }}</p>
            </div>
        </header>

        <p v-if="headerNote" class="invoice-page-note">{{ headerNote }}</p>

        <section class="invoice-page-parties">
            <div>
                <span>خریدار</span>
                <strong>{{ invoice.customer.name }}</strong>
                <p>
                    کد {{ invoice.customer.code }}
                    <template v-if="invoice.customer.phone">
                        · {{ invoice.customer.phone }}
                    </template>
                </p>
            </div>
            <div>
                <span>اطلاعات سند</span>
                <p v-if="invoice.due_date">
                    سررسید: {{ formatDate(invoice.due_date) }}
                </p>
                <p v-if="seller.national_id">
                    شناسه ملی فروشنده: {{ seller.national_id }}
                </p>
                <p v-if="seller.tax_id">شناسه مالیاتی: {{ seller.tax_id }}</p>
            </div>
        </section>

        <table class="invoice-page-table">
            <thead>
                <tr>
                    <th>ردیف</th>
                    <th>کد</th>
                    <th>کالا / خدمات</th>
                    <th>تعداد</th>
                    <th>قیمت واحد</th>
                    <th>تخفیف</th>
                    <th>مالیات</th>
                    <th>جمع</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(item, index) in invoice.items" :key="item.id">
                    <td>{{ quantity.format(index + 1) }}</td>
                    <td dir="ltr">{{ item.product_code ?? '—' }}</td>
                    <td>{{ item.product_name }}</td>
                    <td>
                        {{ quantity.format(Number(item.quantity)) }}
                        {{ item.unit }}
                    </td>
                    <td>{{ money.format(Number(item.unit_price)) }}</td>
                    <td>{{ money.format(Number(item.discount_amount)) }}</td>
                    <td>
                        {{ money.format(Number(item.tax_amount)) }}
                        <small>{{ item.tax_rate }}٪</small>
                    </td>
                    <td>
                        <strong>{{
                            money.format(Number(item.line_total))
                        }}</strong>
                    </td>
                </tr>
            </tbody>
        </table>

        <section class="invoice-page-summary">
            <div>
                <p v-if="invoice.notes">
                    <strong>توضیحات:</strong>
                    {{ invoice.notes }}
                </p>
            </div>
            <dl>
                <div>
                    <dt>جمع کالاها</dt>
                    <dd>{{ money.format(Number(invoice.subtotal)) }}</dd>
                </div>
                <div>
                    <dt>تخفیف</dt>
                    <dd>{{ money.format(Number(invoice.discount_total)) }}</dd>
                </div>
                <div>
                    <dt>مالیات</dt>
                    <dd>{{ money.format(Number(invoice.tax_total)) }}</dd>
                </div>
                <div class="invoice-page-total">
                    <dt>جمع نهایی</dt>
                    <dd>{{ money.format(Number(invoice.total)) }} ریال</dd>
                </div>
            </dl>
        </section>

        <footer class="invoice-page-signatures">
            <div>مهر و امضای فروشنده</div>
            <div>امضای خریدار</div>
        </footer>
    </article>
</template>
