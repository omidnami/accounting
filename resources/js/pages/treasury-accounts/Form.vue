<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Banknote,
    CreditCard,
    Landmark,
    Save,
    WalletCards,
} from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    index as treasuryAccountsIndex,
    store as storeTreasuryAccount,
    update as updateTreasuryAccount,
} from '@/routes/treasury-accounts';

type AccountType = 'cash' | 'bank' | 'card';
type TreasuryAccountDetail = {
    id: number;
    code: string;
    name: string;
    type: AccountType;
    gl_account_id: number;
    is_default: boolean;
    is_active: boolean;
    has_usage: boolean;
    version: number;
};

const props = defineProps<{
    account: TreasuryAccountDetail | null;
    gl_accounts: Array<{ id: number; code: string; name: string }>;
    gl_account_codes: Record<AccountType, string>;
}>();

const form = useForm({
    code: props.account?.code ?? '',
    name: props.account?.name ?? '',
    type: props.account?.type ?? ('cash' as AccountType),
    gl_account_id: props.account?.gl_account_id.toString() ?? '',
    is_default: props.account?.is_default ?? false,
    is_active: props.account?.is_active ?? true,
    version: props.account?.version ?? 0,
});
const fieldError = (key: string) =>
    (form.errors as Record<string, string>)[key];
const eligibleGlAccounts = computed(() =>
    props.gl_accounts.filter(
        (account) => account.code === props.gl_account_codes[form.type],
    ),
);
const methods: Array<{
    value: AccountType;
    label: string;
    description: string;
    icon: typeof Banknote;
}> = [
    {
        value: 'cash',
        label: 'صندوق نقدی',
        description: 'دریافت و پرداخت نقدی',
        icon: Banknote,
    },
    {
        value: 'bank',
        label: 'حساب بانکی',
        description: 'گردش بانکی و تسویه چک',
        icon: Landmark,
    },
    {
        value: 'card',
        label: 'حساب کارتی',
        description: 'درگاه یا پایانه کارتی',
        icon: CreditCard,
    },
];

watch(
    () => form.is_active,
    (isActive) => {
        if (!isActive) {
            form.is_default = false;
        }
    },
);
watch(
    () => form.type,
    () => {
        if (
            !eligibleGlAccounts.value.some(
                (account) => account.id.toString() === form.gl_account_id,
            )
        ) {
            form.gl_account_id = '';
        }
    },
);

const submit = () => {
    if (props.account) {
        form.put(updateTreasuryAccount(props.account.id).url, {
            preserveScroll: true,
        });
        return;
    }

    form.post(storeTreasuryAccount().url, { preserveScroll: true });
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'حساب‌های خزانه',
                href: treasuryAccountsIndex(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="account ? 'ویرایش حساب خزانه' : 'حساب خزانه جدید'" />

    <div class="mx-auto w-full max-w-5xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <WalletCards class="size-5" />
                        <span class="text-xs font-bold">حساب خزانه</span>
                    </div>
                    <h1 class="text-2xl font-black sm:text-3xl">
                        {{
                            account
                                ? 'ویرایش حساب خزانه'
                                : 'اتصال حساب خزانه جدید'
                        }}
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        حساب خزانه باید به یک حساب معین دارایی و فعال در همین
                        فروشگاه متصل باشد.
                    </p>
                </div>
                <Button as-child variant="outline" class="rounded-xl">
                    <Link :href="treasuryAccountsIndex()">
                        <ArrowRight class="size-4" />
                        بازگشت
                    </Link>
                </Button>
            </div>
        </section>

        <div
            v-if="account?.has_usage"
            class="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-xs leading-6 text-amber-800 dark:text-amber-200"
        >
            این حساب گردش مالی دارد؛ کد، نوع و حساب دفتر کل آن قابل تغییر نیست.
        </div>

        <form
            class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_320px]"
            @submit.prevent="submit"
        >
            <div class="space-y-6">
                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="mb-4 text-sm font-bold">نوع حساب</h2>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <button
                            v-for="method in methods"
                            :key="method.value"
                            type="button"
                            class="rounded-2xl border p-4 text-right transition"
                            :class="
                                form.type === method.value
                                    ? 'border-primary/40 bg-primary/[.055] ring-2 ring-primary/10'
                                    : 'bg-background'
                            "
                            :disabled="account?.has_usage"
                            @click="form.type = method.value"
                        >
                            <component
                                :is="method.icon"
                                class="mb-3 size-5 text-primary"
                            />
                            <span class="block text-sm font-bold">{{
                                method.label
                            }}</span>
                            <span
                                class="mt-1 block text-[10px] text-muted-foreground"
                                >{{ method.description }}</span
                            >
                        </button>
                    </div>
                    <InputError :message="form.errors.type" />
                </section>

                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="mb-4 text-sm font-bold">اطلاعات حساب</h2>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="code">کد داخلی *</Label>
                            <Input
                                id="code"
                                v-model="form.code"
                                dir="ltr"
                                placeholder="BANK-MAIN"
                                class="h-11 rounded-xl text-left font-mono"
                                :disabled="account?.has_usage"
                            />
                            <InputError :message="form.errors.code" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="name">نام حساب *</Label>
                            <Input
                                id="name"
                                v-model="form.name"
                                placeholder="بانک اصلی فروشگاه"
                                class="h-11 rounded-xl"
                            />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="gl_account_id"
                                >حساب معین دفتر کل *</Label
                            >
                            <select
                                id="gl_account_id"
                                v-model="form.gl_account_id"
                                class="h-11 rounded-xl border bg-background px-3 text-sm"
                                :disabled="account?.has_usage"
                            >
                                <option value="">انتخاب حساب معین</option>
                                <option
                                    v-for="glAccount in eligibleGlAccounts"
                                    :key="glAccount.id"
                                    :value="glAccount.id.toString()"
                                >
                                    {{ glAccount.code }} · {{ glAccount.name }}
                                </option>
                            </select>
                            <InputError :message="form.errors.gl_account_id" />
                        </div>
                    </div>
                </section>
            </div>

            <aside class="space-y-4 xl:sticky xl:top-6">
                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="text-sm font-bold">تنظیمات</h2>
                    <label
                        class="mt-5 flex cursor-pointer items-start gap-3 rounded-xl border p-3"
                    >
                        <Checkbox
                            :model-value="form.is_default"
                            :disabled="!form.is_active"
                            @update:model-value="
                                form.is_default = Boolean($event)
                            "
                        />
                        <span>
                            <span class="block text-xs font-semibold">
                                حساب پیش‌فرض این نوع
                            </span>
                            <span
                                class="mt-1 block text-[10px] leading-5 text-muted-foreground"
                            >
                                در دریافت و پرداخت‌های بعدی اولویت دارد.
                            </span>
                        </span>
                    </label>
                    <label
                        class="mt-3 flex cursor-pointer items-start gap-3 rounded-xl border p-3"
                    >
                        <Checkbox
                            :model-value="form.is_active"
                            @update:model-value="
                                form.is_active = Boolean($event)
                            "
                        />
                        <span>
                            <span class="block text-xs font-semibold">
                                حساب فعال
                            </span>
                            <span
                                class="mt-1 block text-[10px] leading-5 text-muted-foreground"
                            >
                                فقط حساب فعال در عملیات جدید انتخاب می‌شود.
                            </span>
                        </span>
                    </label>
                    <div
                        v-if="fieldError('treasury_account')"
                        class="mt-4 rounded-xl bg-red-500/10 p-3 text-[10px] leading-5 text-red-700 dark:text-red-300"
                    >
                        {{ fieldError('treasury_account') }}
                    </div>
                    <Button
                        type="submit"
                        class="mt-5 h-11 w-full rounded-xl"
                        :disabled="form.processing"
                    >
                        <Spinner v-if="form.processing" />
                        <Save v-else class="size-4" />
                        ذخیره حساب
                    </Button>
                </section>
            </aside>
        </form>
    </div>
</template>
