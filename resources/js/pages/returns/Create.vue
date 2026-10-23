<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarDays,
    Check,
    CircleAlert,
    PackageMinus,
    ReceiptText,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { normalizeNumber, parseNumber } from '@/lib/number';
import {
    show as showPurchase,
    index as purchasesIndex,
} from '@/routes/purchases';
import * as purchaseReturns from '@/routes/purchases/returns';
import { index as salesIndex, show as showSale } from '@/routes/sales';
import * as salesReturns from '@/routes/sales/returns';

type Kind = 'sales' | 'purchase';
type ReturnItem = {
    id: number;
    product_code: string | null;
    product_name: string;
    unit: string;
    quantity: string;
    returnable_quantity: string;
    unit_price: string;
    discount_amount: string;
    tax_rate: string;
    tax_amount: string;
    line_total: string;
};

const props = defineProps<{
    kind: Kind;
    original: {
        id: number;
        invoice_number: string;
        invoice_date: string;
        status: string;
        total: string;
        party: {
            name: string | null;
            code: string | null;
            phone: string | null;
        };
    };
    items: ReturnItem[];
    defaults: {
        return_date: string;
        submission_token: string;
    };
    fiscal_year: {
        title: string;
        starts_on: string;
        ends_on: string;
    };
}>();

const form = useForm({
    submission_token: props.defaults.submission_token,
    return_date: props.defaults.return_date,
    notes: '',
    items: [] as Array<{ invoice_item_id: number; quantity: string }>,
});
const clientError = ref('');
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
const isSales = computed(() => props.kind === 'sales');
const title = computed(() => (isSales.value ? 'برگشت فروش' : 'برگشت خرید'));
const partyLabel = computed(() => (isSales.value ? 'مشتری' : 'تأمین‌کننده'));
const originalUrl = computed(() =>
    isSales.value
        ? showSale(props.original.id)
        : showPurchase(props.original.id),
);
const indexUrl = computed(() =>
    isSales.value ? salesIndex() : purchasesIndex(),
);
const storeUrl = computed(() =>
    isSales.value
        ? salesReturns.store(props.original.id).url
        : purchaseReturns.store(props.original.id).url,
);
const fieldError = (key: string) =>
    (form.errors as Record<string, string>)[key];
const isSelected = (itemId: number) =>
    form.items.some((item) => item.invoice_item_id === itemId);
const selectedItem = (itemId: number) =>
    form.items.find((item) => item.invoice_item_id === itemId);
const quantityError = (itemId: number) => {
    const index = form.items.findIndex(
        (item) => item.invoice_item_id === itemId,
    );

    return (form.errors as Record<string, string>)[`items.${index}.quantity`];
};

const toggleItem = (item: ReturnItem) => {
    const index = form.items.findIndex(
        (selected) => selected.invoice_item_id === item.id,
    );

    if (index >= 0) {
        form.items.splice(index, 1);
        return;
    }

    form.items.push({
        invoice_item_id: item.id,
        quantity: item.returnable_quantity,
    });
};

const submit = () => {
    clientError.value = '';
    const invalid = form.items.find((selected) => {
        const item = props.items.find(
            (candidate) => candidate.id === selected.invoice_item_id,
        );
        const requested = parseNumber(selected.quantity);

        return (
            !item ||
            requested <= 0 ||
            requested > parseNumber(item.returnable_quantity)
        );
    });

    if (!form.items.length) {
        clientError.value = 'حداقل یک قلم را برای برگشت انتخاب کنید.';
        return;
    }

    if (invalid) {
        clientError.value =
            'مقدار هر قلم باید مثبت و حداکثر برابر مقدار قابل برگشت باشد.';
        return;
    }

    form.transform((data) => ({
        ...data,
        items: data.items.map((item) => ({
            invoice_item_id: item.invoice_item_id,
            quantity: normalizeNumber(item.quantity),
        })),
    })).post(storeUrl.value, { preserveScroll: true });
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'داشبورد', href: '/' },
            { title: 'ثبت برگشت', href: '#' },
        ],
    },
});
</script>

<template>
    <Head :title="`ثبت ${title}`" />

    <div class="mx-auto w-full max-w-6xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <PackageMinus class="size-5" />
                        <span class="text-xs font-bold">{{ title }}</span>
                    </div>
                    <h1 class="text-2xl font-black sm:text-3xl">
                        انتخاب اقلام قابل برگشت
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        فاکتور اصلی {{ original.invoice_number }} ·
                        {{ original.party.name }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <Button as-child variant="outline" class="rounded-xl">
                        <Link :href="indexUrl">
                            <ArrowRight class="size-4" />
                            فهرست
                        </Link>
                    </Button>
                    <Button as-child variant="outline" class="rounded-xl">
                        <Link :href="originalUrl">فاکتور اصلی</Link>
                    </Button>
                </div>
            </div>
        </section>

        <form
            class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_320px]"
            @submit.prevent="submit"
        >
            <div class="space-y-5">
                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="return_date">تاریخ برگشت *</Label>
                            <div class="relative">
                                <CalendarDays
                                    class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                                />
                                <Input
                                    id="return_date"
                                    v-model="form.return_date"
                                    type="date"
                                    :min="original.invoice_date"
                                    :max="fiscal_year.ends_on"
                                    class="h-11 rounded-xl pr-9"
                                />
                            </div>
                            <InputError :message="form.errors.return_date" />
                        </div>
                        <div class="rounded-xl bg-muted/35 px-4 py-3">
                            <p class="text-[10px] text-muted-foreground">
                                {{ partyLabel }}
                            </p>
                            <p class="mt-1 text-sm font-bold">
                                {{ original.party.name ?? 'نامشخص' }}
                            </p>
                            <p class="mt-1 text-[10px] text-muted-foreground">
                                {{ original.party.code ?? 'بدون کد' }} ·
                                {{ original.party.phone ?? 'بدون تلفن' }}
                            </p>
                        </div>
                    </div>
                    <div v-if="!isSales" class="mt-4 grid gap-2">
                        <Label for="notes">یادداشت برگشت</Label>
                        <textarea
                            id="notes"
                            v-model="form.notes"
                            rows="3"
                            class="rounded-xl border border-input bg-background px-4 py-3 text-sm outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                        />
                        <InputError :message="form.errors.notes" />
                    </div>
                </section>

                <section
                    class="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
                >
                    <div class="border-b border-border/70 px-5 py-4">
                        <h2 class="text-sm font-bold">اقلام فاکتور</h2>
                        <p class="mt-1 text-[10px] text-muted-foreground">
                            فقط مقدار باقی‌مانده هر ردیف قابل برگشت است.
                        </p>
                    </div>
                    <div v-if="items.length" class="divide-y">
                        <article
                            v-for="(item, index) in items"
                            :key="item.id"
                            class="p-5"
                            :class="
                                isSelected(item.id)
                                    ? 'bg-primary/[.035]'
                                    : 'bg-card'
                            "
                        >
                            <button
                                type="button"
                                class="flex w-full items-start justify-between gap-4 text-right"
                                @click="toggleItem(item)"
                            >
                                <span>
                                    <span class="block text-sm font-bold">
                                        {{ item.product_name }}
                                    </span>
                                    <span
                                        class="mt-1 block text-[10px] text-muted-foreground"
                                    >
                                        {{ item.product_code ?? 'بدون کد' }} ·
                                        {{ isSales ? 'فروش' : 'خرید' }}
                                        {{
                                            quantity.format(
                                                Number(item.quantity),
                                            )
                                        }}
                                        {{ item.unit }} · قابل برگشت
                                        {{
                                            quantity.format(
                                                Number(
                                                    item.returnable_quantity,
                                                ),
                                            )
                                        }}
                                        {{ item.unit }}
                                    </span>
                                </span>
                                <span
                                    class="flex size-7 shrink-0 items-center justify-center rounded-full border"
                                    :class="
                                        isSelected(item.id)
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-muted-foreground/30'
                                    "
                                >
                                    <Check
                                        v-if="isSelected(item.id)"
                                        class="size-4"
                                    />
                                </span>
                            </button>
                            <div
                                v-if="isSelected(item.id)"
                                class="mt-4 grid gap-2"
                            >
                                <Label :for="`return-qty-${item.id}`">
                                    مقدار برگشت
                                </Label>
                                <Input
                                    :id="`return-qty-${item.id}`"
                                    v-model="selectedItem(item.id)!.quantity"
                                    inputmode="decimal"
                                    dir="ltr"
                                    class="h-10 rounded-xl text-left"
                                />
                                <InputError :message="quantityError(item.id)" />
                            </div>
                        </article>
                    </div>
                    <div v-else class="px-5 py-12 text-center">
                        <ReceiptText
                            class="mx-auto size-9 text-muted-foreground/40"
                        />
                        <p class="mt-3 text-sm font-bold">
                            قلم قابل برگشتی باقی نمانده است
                        </p>
                    </div>
                </section>
            </div>

            <aside class="space-y-4 xl:sticky xl:top-6">
                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <h2 class="text-sm font-bold">خلاصه برگشت</h2>
                    <div class="mt-5 flex justify-between text-xs">
                        <span class="text-muted-foreground"
                            >اقلام انتخاب‌شده</span
                        >
                        <span class="font-black">{{ form.items.length }}</span>
                    </div>
                    <div class="mt-3 flex justify-between text-xs">
                        <span class="text-muted-foreground"
                            >جمع فاکتور اصلی</span
                        >
                        <span class="font-bold">
                            {{ money.format(Number(original.total)) }} ریال
                        </span>
                    </div>
                    <div
                        v-if="clientError || fieldError('return')"
                        class="mt-4 flex items-start gap-2 rounded-xl bg-red-500/10 px-3 py-2 text-[10px] leading-5 text-red-700 dark:text-red-300"
                    >
                        <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
                        {{ clientError || fieldError('return') }}
                    </div>
                    <Button
                        type="submit"
                        class="mt-5 h-11 w-full rounded-xl"
                        :disabled="
                            form.processing ||
                            !items.length ||
                            !form.items.length
                        "
                    >
                        <Spinner v-if="form.processing" />
                        <PackageMinus v-else class="size-4" />
                        ایجاد پیش‌نویس برگشت
                    </Button>
                </section>
                <section
                    class="rounded-2xl border border-primary/15 bg-primary/[.045] p-4"
                >
                    <p class="text-[10px] leading-6 text-muted-foreground">
                        پیش‌نویس پس از ایجاد، هنوز موجودی یا سند مالی را تغییر
                        نمی‌دهد. تأیید نهایی در صفحه جزئیات انجام می‌شود.
                    </p>
                </section>
            </aside>
        </form>
    </div>
</template>
