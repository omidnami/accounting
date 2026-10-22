<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    Check,
    CircleCheck,
    Info,
    Save,
    ShoppingBag,
    Truck,
    UserRound,
    UsersRound,
} from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    index as partiesIndex,
    store as storeParty,
    update as updateParty,
} from '@/routes/parties';

type Role = 'customer' | 'supplier';

type PartyDetail = {
    id: number;
    code: string;
    entity_type: 'individual' | 'organization';
    name: string;
    phone: string | null;
    email: string | null;
    national_id: string | null;
    tax_id: string | null;
    address: string | null;
    is_active: boolean;
    version: number;
    customer: {
        credit_limit: string | null;
        payment_terms: string | null;
        sales_notes: string | null;
        is_active: boolean;
    } | null;
    supplier: {
        payment_terms: string | null;
        lead_time_days: number | null;
        purchasing_notes: string | null;
        is_active: boolean;
    } | null;
};

const props = defineProps<{
    party: PartyDetail | null;
}>();

const initialRoles: Role[] = [];

if (props.party?.customer?.is_active) {
    initialRoles.push('customer');
}

if (props.party?.supplier?.is_active) {
    initialRoles.push('supplier');
}

if (props.party === null && !initialRoles.length) {
    initialRoles.push('customer');
}

const form = useForm({
    code: props.party?.code ?? '',
    entity_type: props.party?.entity_type ?? ('individual' as const),
    name: props.party?.name ?? '',
    phone: props.party?.phone ?? '',
    email: props.party?.email ?? '',
    national_id: props.party?.national_id ?? '',
    tax_id: props.party?.tax_id ?? '',
    address: props.party?.address ?? '',
    is_active: props.party?.is_active ?? true,
    version: props.party?.version ?? 0,
    roles: initialRoles,
    customer: {
        credit_limit: props.party?.customer?.credit_limit ?? '',
        payment_terms: props.party?.customer?.payment_terms ?? '',
        sales_notes: props.party?.customer?.sales_notes ?? '',
    },
    supplier: {
        payment_terms: props.party?.supplier?.payment_terms ?? '',
        lead_time_days: props.party?.supplier?.lead_time_days ?? '',
        purchasing_notes: props.party?.supplier?.purchasing_notes ?? '',
    },
});

const hasRole = (role: Role) => form.roles.includes(role);

const toggleRole = (role: Role) => {
    const roles = new Set<Role>(form.roles);

    if (roles.has(role)) {
        roles.delete(role);
    } else {
        roles.add(role);
    }

    form.roles = Array.from(roles);
};

const fieldError = (key: string) =>
    (form.errors as Record<string, string>)[key];

const submit = () => {
    if (props.party) {
        form.put(updateParty(props.party.id).url, {
            preserveScroll: true,
        });
        return;
    }

    form.post(storeParty().url, {
        preserveScroll: true,
    });
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'طرف‌حساب‌ها',
                href: partiesIndex(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="party ? 'ویرایش طرف‌حساب' : 'طرف‌حساب جدید'" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="relative overflow-hidden rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="relative flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <UsersRound class="size-5" />
                        <span class="text-xs font-bold"
                            >هویت یکپارچه طرف‌حساب</span
                        >
                    </div>
                    <h1 class="text-2xl font-black tracking-tight sm:text-3xl">
                        {{
                            party
                                ? 'ویرایش اطلاعات طرف‌حساب'
                                : 'ثبت طرف‌حساب جدید'
                        }}
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        اطلاعات هویتی را یک‌بار ثبت کنید و نقش‌های تجاری مورد
                        نیاز را به همان هویت متصل کنید.
                    </p>
                </div>
                <Button as-child variant="outline" class="w-fit rounded-xl">
                    <Link :href="partiesIndex()">
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
                            <UserRound class="size-5" />
                        </span>
                        <div>
                            <h2 class="text-sm font-bold">اطلاعات هویتی</h2>
                            <p class="mt-1 text-xs text-muted-foreground">
                                این اطلاعات میان تمام نقش‌های طرف‌حساب مشترک
                                است.
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="code">کد طرف‌حساب *</Label>
                            <Input
                                id="code"
                                v-model="form.code"
                                name="code"
                                placeholder="مثلاً C-1001"
                                dir="ltr"
                                class="h-11 rounded-xl px-4 text-left font-mono"
                                autocomplete="off"
                            />
                            <p class="text-[10px] text-muted-foreground">
                                کد در سطح این فروشگاه یکتا است.
                            </p>
                            <InputError :message="form.errors.code" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="entity_type">نوع شخص *</Label>
                            <select
                                id="entity_type"
                                v-model="form.entity_type"
                                name="entity_type"
                                class="h-11 w-full rounded-xl border border-input bg-background px-3 text-sm transition outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                            >
                                <option value="individual">شخص حقیقی</option>
                                <option value="organization">شخص حقوقی</option>
                            </select>
                            <InputError :message="form.errors.entity_type" />
                        </div>

                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="name">نام / نام تجاری *</Label>
                            <Input
                                id="name"
                                v-model="form.name"
                                name="name"
                                placeholder="نام کامل شخص یا شرکت"
                                class="h-11 rounded-xl px-4"
                                autocomplete="name"
                            />
                            <InputError :message="form.errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="phone">تلفن</Label>
                            <Input
                                id="phone"
                                v-model="form.phone"
                                name="phone"
                                placeholder="۰۹۱۲۱۲۳۴۵۶۷"
                                dir="ltr"
                                class="h-11 rounded-xl px-4 text-left"
                                autocomplete="tel"
                            />
                            <InputError :message="form.errors.phone" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="email">ایمیل</Label>
                            <Input
                                id="email"
                                v-model="form.email"
                                type="email"
                                name="email"
                                placeholder="name@example.com"
                                dir="ltr"
                                class="h-11 rounded-xl px-4 text-left"
                                autocomplete="email"
                            />
                            <InputError :message="form.errors.email" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="national_id">کد ملی / شناسه ملی</Label>
                            <Input
                                id="national_id"
                                v-model="form.national_id"
                                name="national_id"
                                placeholder="کد ملی یا شناسه ملی"
                                dir="ltr"
                                class="h-11 rounded-xl px-4 text-left"
                            />
                            <InputError :message="form.errors.national_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="tax_id">شناسه مالیاتی</Label>
                            <Input
                                id="tax_id"
                                v-model="form.tax_id"
                                name="tax_id"
                                placeholder="شناسه مالیاتی"
                                dir="ltr"
                                class="h-11 rounded-xl px-4 text-left"
                            />
                            <InputError :message="form.errors.tax_id" />
                        </div>

                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="address">نشانی</Label>
                            <textarea
                                id="address"
                                v-model="form.address"
                                name="address"
                                rows="3"
                                placeholder="نشانی کامل طرف‌حساب"
                                class="w-full resize-y rounded-xl border border-input bg-background px-4 py-3 text-sm leading-7 transition outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                            />
                            <InputError :message="form.errors.address" />
                        </div>
                    </div>
                </section>

                <section
                    class="rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:p-6"
                >
                    <div class="mb-5">
                        <h2 class="text-sm font-bold">نقش‌های تجاری</h2>
                        <p class="mt-1 text-xs leading-6 text-muted-foreground">
                            یک طرف‌حساب می‌تواند هم‌زمان هر دو نقش را داشته
                            باشد. برداشتن نقش، سابقه مالی را حذف نمی‌کند و فقط
                            نقش را غیرفعال می‌سازد.
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <button
                            type="button"
                            class="rounded-2xl border p-4 text-right transition"
                            :class="
                                hasRole('customer')
                                    ? 'border-sky-500/40 bg-sky-500/8 ring-2 ring-sky-500/10'
                                    : 'border-border bg-background hover:bg-muted/30'
                            "
                            :aria-pressed="hasRole('customer')"
                            @click="toggleRole('customer')"
                        >
                            <div class="flex items-start gap-3">
                                <span
                                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-sky-500/10 text-sky-600"
                                >
                                    <ShoppingBag class="size-5" />
                                </span>
                                <div class="flex-1">
                                    <div
                                        class="flex items-center justify-between gap-2"
                                    >
                                        <span class="text-sm font-bold"
                                            >مشتری</span
                                        >
                                        <span
                                            class="flex size-5 items-center justify-center rounded-full border"
                                            :class="
                                                hasRole('customer')
                                                    ? 'border-sky-600 bg-sky-600 text-white'
                                                    : 'border-muted-foreground/30'
                                            "
                                        >
                                            <Check
                                                v-if="hasRole('customer')"
                                                class="size-3"
                                            />
                                        </span>
                                    </div>
                                    <p
                                        class="mt-1 text-[10px] leading-5 text-muted-foreground"
                                    >
                                        فروش اعتباری، سقف اعتبار و شرایط پرداخت.
                                    </p>
                                </div>
                            </div>
                        </button>

                        <button
                            type="button"
                            class="rounded-2xl border p-4 text-right transition"
                            :class="
                                hasRole('supplier')
                                    ? 'border-amber-500/40 bg-amber-500/8 ring-2 ring-amber-500/10'
                                    : 'border-border bg-background hover:bg-muted/30'
                            "
                            :aria-pressed="hasRole('supplier')"
                            @click="toggleRole('supplier')"
                        >
                            <div class="flex items-start gap-3">
                                <span
                                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600"
                                >
                                    <Truck class="size-5" />
                                </span>
                                <div class="flex-1">
                                    <div
                                        class="flex items-center justify-between gap-2"
                                    >
                                        <span class="text-sm font-bold"
                                            >تأمین‌کننده</span
                                        >
                                        <span
                                            class="flex size-5 items-center justify-center rounded-full border"
                                            :class="
                                                hasRole('supplier')
                                                    ? 'border-amber-600 bg-amber-600 text-white'
                                                    : 'border-muted-foreground/30'
                                            "
                                        >
                                            <Check
                                                v-if="hasRole('supplier')"
                                                class="size-3"
                                            />
                                        </span>
                                    </div>
                                    <p
                                        class="mt-1 text-[10px] leading-5 text-muted-foreground"
                                    >
                                        خرید، شرایط تسویه و زمان تأمین کالا.
                                    </p>
                                </div>
                            </div>
                        </button>
                    </div>
                    <InputError class="mt-2" :message="form.errors.roles" />
                    <InputError :message="fieldError('roles.0')" />

                    <div
                        v-if="hasRole('customer')"
                        class="mt-6 rounded-2xl border border-sky-500/15 bg-sky-500/[.035] p-4 sm:p-5"
                    >
                        <div class="mb-4 flex items-center gap-2">
                            <ShoppingBag class="size-4 text-sky-600" />
                            <h3 class="text-xs font-bold">
                                تنظیمات پروفایل مشتری
                            </h3>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="credit_limit"
                                    >سقف اعتبار (ریال)</Label
                                >
                                <Input
                                    id="credit_limit"
                                    v-model="form.customer.credit_limit"
                                    name="customer[credit_limit]"
                                    inputmode="decimal"
                                    dir="ltr"
                                    placeholder="0"
                                    class="h-11 rounded-xl px-4 text-left"
                                />
                                <InputError
                                    :message="
                                        fieldError('customer.credit_limit')
                                    "
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="customer_payment_terms"
                                    >شرایط پرداخت</Label
                                >
                                <Input
                                    id="customer_payment_terms"
                                    v-model="form.customer.payment_terms"
                                    name="customer[payment_terms]"
                                    placeholder="مثلاً نقدی یا ۳۰ روزه"
                                    class="h-11 rounded-xl px-4"
                                />
                                <InputError
                                    :message="
                                        fieldError('customer.payment_terms')
                                    "
                                />
                            </div>
                            <div class="grid gap-2 sm:col-span-2">
                                <Label for="sales_notes">یادداشت فروش</Label>
                                <textarea
                                    id="sales_notes"
                                    v-model="form.customer.sales_notes"
                                    name="customer[sales_notes]"
                                    rows="3"
                                    placeholder="توضیحات خاص این مشتری"
                                    class="w-full resize-y rounded-xl border border-input bg-background px-4 py-3 text-sm leading-7 transition outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                                />
                                <InputError
                                    :message="
                                        fieldError('customer.sales_notes')
                                    "
                                />
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="hasRole('supplier')"
                        class="mt-6 rounded-2xl border border-amber-500/15 bg-amber-500/[.035] p-4 sm:p-5"
                    >
                        <div class="mb-4 flex items-center gap-2">
                            <Truck class="size-4 text-amber-600" />
                            <h3 class="text-xs font-bold">
                                تنظیمات پروفایل تأمین‌کننده
                            </h3>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="supplier_payment_terms"
                                    >شرایط پرداخت</Label
                                >
                                <Input
                                    id="supplier_payment_terms"
                                    v-model="form.supplier.payment_terms"
                                    name="supplier[payment_terms]"
                                    placeholder="مثلاً تسویه ۴۵ روزه"
                                    class="h-11 rounded-xl px-4"
                                />
                                <InputError
                                    :message="
                                        fieldError('supplier.payment_terms')
                                    "
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="lead_time_days"
                                    >زمان تأمین (روز)</Label
                                >
                                <Input
                                    id="lead_time_days"
                                    v-model="form.supplier.lead_time_days"
                                    name="supplier[lead_time_days]"
                                    type="number"
                                    min="0"
                                    max="3650"
                                    dir="ltr"
                                    placeholder="0"
                                    class="h-11 rounded-xl px-4 text-left"
                                />
                                <InputError
                                    :message="
                                        fieldError('supplier.lead_time_days')
                                    "
                                />
                            </div>
                            <div class="grid gap-2 sm:col-span-2">
                                <Label for="purchasing_notes"
                                    >یادداشت خرید</Label
                                >
                                <textarea
                                    id="purchasing_notes"
                                    v-model="form.supplier.purchasing_notes"
                                    name="supplier[purchasing_notes]"
                                    rows="3"
                                    placeholder="توضیحات خاص این تأمین‌کننده"
                                    class="w-full resize-y rounded-xl border border-input bg-background px-4 py-3 text-sm leading-7 transition outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                                />
                                <InputError
                                    :message="
                                        fieldError('supplier.purchasing_notes')
                                    "
                                />
                            </div>
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
                            <Building2
                                v-if="form.entity_type === 'organization'"
                                class="size-6"
                            />
                            <UserRound v-else class="size-6" />
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold">
                                {{ form.name || 'نام طرف‌حساب' }}
                            </p>
                            <p
                                dir="ltr"
                                class="mt-1 truncate text-right font-mono text-[10px] text-muted-foreground"
                            >
                                {{ form.code || 'CODE' }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-5 flex flex-wrap gap-2">
                        <span
                            v-for="role in form.roles"
                            :key="role"
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-semibold"
                            :class="
                                role === 'customer'
                                    ? 'bg-sky-500/10 text-sky-700 dark:text-sky-300'
                                    : 'bg-amber-500/10 text-amber-700 dark:text-amber-300'
                            "
                        >
                            <ShoppingBag
                                v-if="role === 'customer'"
                                class="size-3"
                            />
                            <Truck v-else class="size-3" />
                            {{ role === 'customer' ? 'مشتری' : 'تأمین‌کننده' }}
                        </span>
                    </div>

                    <label
                        class="mt-5 flex cursor-pointer items-start gap-3 rounded-xl border bg-muted/25 p-3"
                    >
                        <Checkbox v-model="form.is_active" class="mt-0.5" />
                        <span>
                            <span class="block text-xs font-semibold"
                                >هویت فعال باشد</span
                            >
                            <span
                                class="mt-1 block text-[10px] leading-5 text-muted-foreground"
                                >طرف‌حساب غیرفعال در فرم‌های جدید قابل انتخاب
                                نیست.</span
                            >
                        </span>
                    </label>

                    <InputError class="mt-3" :message="fieldError('party')" />
                    <Button
                        v-if="party && fieldError('party')"
                        as-child
                        variant="outline"
                        class="mt-2 h-9 w-full rounded-xl"
                    >
                        <Link :href="updateParty(party.id).url">
                            بارگذاری اطلاعات تازه
                        </Link>
                    </Button>

                    <Button
                        type="submit"
                        class="mt-5 h-11 w-full rounded-xl"
                        :disabled="
                            form.processing || Boolean(fieldError('party'))
                        "
                    >
                        <Spinner v-if="form.processing" />
                        <Save v-else class="size-4" />
                        {{ party ? 'ذخیره تغییرات' : 'ایجاد طرف‌حساب' }}
                    </Button>
                </section>

                <section
                    class="rounded-2xl border border-primary/15 bg-primary/[.045] p-4"
                >
                    <div class="flex items-start gap-2.5">
                        <Info class="mt-0.5 size-4 shrink-0 text-primary" />
                        <p class="text-[10px] leading-6 text-muted-foreground">
                            حذف طرف‌حساب دارای گردش مالی مجاز نیست. برای پایان
                            همکاری، هویت یا نقش مربوط را غیرفعال کنید تا
                            گزارش‌های تاریخی حفظ شوند.
                        </p>
                    </div>
                </section>

                <section
                    v-if="party"
                    class="rounded-2xl border border-border/70 bg-card p-4"
                >
                    <div class="flex items-start gap-2.5">
                        <CircleCheck
                            class="mt-0.5 size-4 shrink-0 text-emerald-600"
                        />
                        <p class="text-[10px] leading-6 text-muted-foreground">
                            شناسه داخلی این هویت
                            <span dir="ltr" class="font-mono"
                                >#{{ party.id }}</span
                            >
                            است و تغییر نمی‌کند.
                        </p>
                    </div>
                </section>
            </aside>
        </form>
    </div>
</template>
