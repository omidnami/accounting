<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Boxes,
    CircleCheck,
    Info,
    Package,
    Save,
    Tag,
} from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    index as productsIndex,
    store as storeProduct,
    update as updateProduct,
} from '@/routes/products';

type ProductDetail = {
    id: number;
    code: string;
    barcode: string | null;
    name: string;
    unit: string;
    purchase_price: string;
    sale_price: string;
    tax_rate: string;
    reorder_level: string;
    description: string | null;
    is_active: boolean;
    version: number;
};

const props = defineProps<{
    product: ProductDetail | null;
}>();

const form = useForm({
    code: props.product?.code ?? '',
    barcode: props.product?.barcode ?? '',
    name: props.product?.name ?? '',
    unit: props.product?.unit ?? 'عدد',
    purchase_price: props.product?.purchase_price ?? '0',
    sale_price: props.product?.sale_price ?? '0',
    tax_rate: props.product?.tax_rate ?? '0',
    reorder_level: props.product?.reorder_level ?? '0',
    description: props.product?.description ?? '',
    is_active: props.product?.is_active ?? true,
    version: props.product?.version ?? 0,
});

const money = new Intl.NumberFormat('fa-IR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});
const fieldError = (key: string) =>
    (form.errors as Record<string, string>)[key];
const normalizeNumber = (value: string) =>
    value
        .replace(/[۰-۹]/g, (digit) => `${'۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)}`)
        .replace(/[٠-٩]/g, (digit) => `${'٠١٢٣٤٥٦٧٨٩'.indexOf(digit)}`)
        .replace(/[٫]/g, '.')
        .replace(/[٬,،\s]/g, '');

type NumericField =
    | 'purchase_price'
    | 'sale_price'
    | 'tax_rate'
    | 'reorder_level';

const normalizeNumericField = (field: NumericField) => {
    form[field] = normalizeNumber(form[field]);
};

const submit = () => {
    if (props.product) {
        form.put(updateProduct(props.product.id).url, {
            preserveScroll: true,
        });
        return;
    }

    form.post(storeProduct().url, {
        preserveScroll: true,
    });
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'کالا و موجودی',
                href: productsIndex(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="product ? 'ویرایش کالا' : 'کالای جدید'" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="relative overflow-hidden rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="relative flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <Boxes class="size-5" />
                        <span class="text-xs font-bold">شناسنامه کالا</span>
                    </div>
                    <h1 class="text-2xl font-black tracking-tight sm:text-3xl">
                        {{ product ? 'ویرایش کالا' : 'ثبت کالای جدید' }}
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        مشخصات، قیمت‌های پایه و نقطه سفارش را ثبت کنید. تغییر
                        موجودی از این فرم انجام نمی‌شود.
                    </p>
                </div>
                <Button as-child variant="outline" class="w-fit rounded-xl">
                    <Link :href="productsIndex()">
                        <ArrowRight class="size-4" />
                        بازگشت به فهرست
                    </Link>
                </Button>
            </div>
        </section>

        <form
            class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_330px]"
            @submit.prevent="submit"
        >
            <div class="space-y-6">
                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:p-6"
                >
                    <div class="mb-6 flex items-start gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                        >
                            <Package class="size-5" />
                        </span>
                        <div>
                            <h2 class="text-sm font-bold">مشخصات اصلی</h2>
                            <p class="mt-1 text-xs text-muted-foreground">
                                کد و بارکد باید در کاتالوگ یکتا باشند.
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="code">کد کالا *</Label>
                            <Input
                                id="code"
                                v-model="form.code"
                                name="code"
                                placeholder="مثلاً P-1001"
                                dir="ltr"
                                class="h-11 rounded-xl px-4 text-left font-mono"
                                autocomplete="off"
                            />
                            <InputError :message="form.errors.code" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="barcode">بارکد</Label>
                            <Input
                                id="barcode"
                                v-model="form.barcode"
                                name="barcode"
                                placeholder="بارکد کالا"
                                dir="ltr"
                                class="h-11 rounded-xl px-4 text-left font-mono"
                                autocomplete="off"
                            />
                            <InputError :message="form.errors.barcode" />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="name">نام کالا *</Label>
                            <Input
                                id="name"
                                v-model="form.name"
                                name="name"
                                placeholder="نام کامل کالا"
                                class="h-11 rounded-xl px-4"
                            />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="unit">واحد فروش *</Label>
                            <Input
                                id="unit"
                                v-model="form.unit"
                                name="unit"
                                list="product-units"
                                placeholder="عدد"
                                class="h-11 rounded-xl px-4"
                            />
                            <datalist id="product-units">
                                <option value="عدد" />
                                <option value="بسته" />
                                <option value="کیلوگرم" />
                                <option value="گرم" />
                                <option value="لیتر" />
                                <option value="متر" />
                            </datalist>
                            <InputError :message="form.errors.unit" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="tax_rate">نرخ مالیات (٪)</Label>
                            <Input
                                id="tax_rate"
                                v-model="form.tax_rate"
                                name="tax_rate"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                dir="ltr"
                                class="h-11 rounded-xl px-4 text-left"
                            />
                            <InputError :message="form.errors.tax_rate" />
                        </div>
                    </div>
                </section>

                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:p-6"
                >
                    <div class="mb-6 flex items-start gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600"
                        >
                            <Tag class="size-5" />
                        </span>
                        <div>
                            <h2 class="text-sm font-bold">
                                قیمت و کنترل موجودی
                            </h2>
                            <p class="mt-1 text-xs text-muted-foreground">
                                این قیمت‌ها مقدار پیش‌فرض هستند؛ فاکتور از
                                snapshot مستقل استفاده می‌کند.
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="purchase_price"
                                >قیمت خرید پیش‌فرض (ریال) *</Label
                            >
                            <Input
                                id="purchase_price"
                                v-model="form.purchase_price"
                                name="purchase_price"
                                inputmode="decimal"
                                dir="ltr"
                                class="h-11 rounded-xl px-4 text-left"
                                @blur="normalizeNumericField('purchase_price')"
                            />
                            <InputError :message="form.errors.purchase_price" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="sale_price"
                                >قیمت فروش پیش‌فرض (ریال) *</Label
                            >
                            <Input
                                id="sale_price"
                                v-model="form.sale_price"
                                name="sale_price"
                                inputmode="decimal"
                                dir="ltr"
                                class="h-11 rounded-xl px-4 text-left"
                                @blur="normalizeNumericField('sale_price')"
                            />
                            <InputError :message="form.errors.sale_price" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="reorder_level">نقطه سفارش *</Label>
                            <Input
                                id="reorder_level"
                                v-model="form.reorder_level"
                                name="reorder_level"
                                inputmode="decimal"
                                dir="ltr"
                                class="h-11 rounded-xl px-4 text-left"
                                @blur="normalizeNumericField('reorder_level')"
                            />
                            <p class="text-[10px] text-muted-foreground">
                                در این مقدار یا کمتر، کالا کم‌موجود محسوب
                                می‌شود.
                            </p>
                            <InputError :message="form.errors.reorder_level" />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="description">توضیحات</Label>
                            <textarea
                                id="description"
                                v-model="form.description"
                                name="description"
                                rows="4"
                                placeholder="توضیحات تکمیلی کالا"
                                class="w-full resize-y rounded-xl border border-input bg-background px-4 py-3 text-sm leading-7 transition outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                            />
                            <InputError :message="form.errors.description" />
                        </div>
                    </div>
                </section>
            </div>

            <aside class="space-y-4 xl:sticky xl:top-6">
                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-primary"
                        >
                            <Package class="size-6" />
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold">
                                {{ form.name || 'نام کالا' }}
                            </p>
                            <p
                                dir="ltr"
                                class="mt-1 truncate text-right font-mono text-[10px] text-muted-foreground"
                            >
                                {{ form.code || 'PRODUCT-CODE' }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="mt-5 grid grid-cols-2 gap-2 rounded-xl bg-muted/35 p-3"
                    >
                        <div>
                            <p class="text-[9px] text-muted-foreground">
                                قیمت فروش
                            </p>
                            <p class="mt-1 text-xs font-bold">
                                {{ money.format(Number(form.sale_price || 0)) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-[9px] text-muted-foreground">
                                نقطه سفارش
                            </p>
                            <p class="mt-1 text-xs font-bold">
                                {{ form.reorder_level || 0 }}
                                {{ form.unit }}
                            </p>
                        </div>
                    </div>

                    <label
                        class="mt-4 flex cursor-pointer items-start gap-3 rounded-xl border bg-muted/25 p-3"
                    >
                        <Checkbox v-model="form.is_active" class="mt-0.5" />
                        <span>
                            <span class="block text-xs font-semibold"
                                >کالا فعال باشد</span
                            >
                            <span
                                class="mt-1 block text-[10px] leading-5 text-muted-foreground"
                                >کالای غیرفعال در فاکتور و حرکت انبار جدید قابل
                                استفاده نیست.</span
                            >
                        </span>
                    </label>

                    <InputError class="mt-3" :message="fieldError('product')" />
                    <Button
                        v-if="product && fieldError('product')"
                        as-child
                        variant="outline"
                        class="mt-2 h-9 w-full rounded-xl"
                    >
                        <Link :href="updateProduct(product.id).url">
                            بارگذاری اطلاعات تازه
                        </Link>
                    </Button>

                    <Button
                        type="submit"
                        class="mt-5 h-11 w-full rounded-xl"
                        :disabled="
                            form.processing || Boolean(fieldError('product'))
                        "
                    >
                        <Spinner v-if="form.processing" />
                        <Save v-else class="size-4" />
                        {{ product ? 'ذخیره تغییرات' : 'ایجاد کالا' }}
                    </Button>
                </section>

                <section
                    class="rounded-2xl border border-primary/15 bg-primary/[.045] p-4"
                >
                    <div class="flex items-start gap-2.5">
                        <Info class="mt-0.5 size-4 shrink-0 text-primary" />
                        <p class="text-[10px] leading-6 text-muted-foreground">
                            تغییر قیمت کالا روی فاکتورهای قبلی اثر ندارد؛ زیرا
                            اطلاعات قیمت در زمان فاکتور snapshot می‌شود.
                        </p>
                    </div>
                </section>

                <section
                    v-if="product"
                    class="rounded-2xl border border-border/70 bg-card p-4"
                >
                    <div class="flex items-start gap-2.5">
                        <CircleCheck
                            class="mt-0.5 size-4 shrink-0 text-emerald-600"
                        />
                        <p class="text-[10px] leading-6 text-muted-foreground">
                            شناسه کالا
                            <span dir="ltr" class="font-mono"
                                >#{{ product.id }}</span
                            >
                            ثابت می‌ماند و موجودی به همین شناسه متصل است.
                        </p>
                    </div>
                </section>
            </aside>
        </form>
    </div>
</template>
