<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, Save } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit, index, store, update } from '@/routes/accounts';

type Account = {
    id: number;
    code: string;
    name: string;
    type: string;
    level: string;
    normal_balance: string;
    parent_id: number | null;
    requires_party: boolean;
    description: string | null;
    is_active: boolean;
    usage_count: number;
};
type Parent = { id: number; label: string };

const props = defineProps<{
    account: Account | null;
    parents: Parent[];
}>();

const form = useForm({
    code: props.account?.code ?? '',
    name: props.account?.name ?? '',
    type: props.account?.type ?? 'asset',
    level: props.account?.level ?? 'subsidiary',
    normal_balance: props.account?.normal_balance ?? 'debit',
    parent_id: props.account?.parent_id ?? null,
    requires_party: props.account?.requires_party ?? false,
    description: props.account?.description ?? '',
    is_active: props.account?.is_active ?? true,
});
const fieldError = (key: string) =>
    (form.errors as Record<string, string>)[key];
const submit = () => {
    if (props.account) {
        form.put(update(props.account.id).url, { preserveScroll: true });
        return;
    }
    form.post(store().url, { preserveScroll: true });
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'درخت حساب', href: index() }],
    },
});
</script>

<template>
    <Head :title="account ? 'ویرایش حساب' : 'حساب جدید'" />
    <div class="mx-auto w-full max-w-3xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section class="flex items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-black">
                    {{ account ? 'ویرایش حساب' : 'حساب جدید' }}
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    کد، ماهیت، سطح، مانده طبیعی و والد پس از استفاده قابل تغییر
                    نیستند.
                </p>
            </div>
            <Button as-child variant="outline" class="rounded-xl">
                <Link :href="index()">
                    <ArrowRight class="size-4" /> بازگشت
                </Link>
            </Button>
        </section>

        <form
            class="space-y-5 rounded-2xl border bg-card p-5 sm:p-6"
            @submit.prevent="submit"
        >
            <div
                v-if="fieldError('account')"
                class="rounded-xl bg-red-500/10 p-3 text-xs text-red-700 dark:text-red-300"
            >
                {{ fieldError('account') }}
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="code">کد حساب *</Label>
                    <Input
                        id="code"
                        v-model="form.code"
                        dir="ltr"
                        inputmode="numeric"
                        :disabled="Boolean(account?.usage_count)"
                    />
                    <InputError :message="fieldError('code')" />
                </div>
                <div class="grid gap-2">
                    <Label for="name">نام حساب *</Label>
                    <Input id="name" v-model="form.name" />
                    <InputError :message="fieldError('name')" />
                </div>
                <div class="grid gap-2">
                    <Label for="type">ماهیت *</Label>
                    <select
                        id="type"
                        v-model="form.type"
                        class="h-10 rounded-md border bg-background px-3 text-sm"
                        :disabled="Boolean(account?.usage_count)"
                    >
                        <option value="asset">دارایی</option>
                        <option value="liability">بدهی</option>
                        <option value="equity">حقوق مالکانه</option>
                        <option value="revenue">درآمد</option>
                        <option value="expense">هزینه</option>
                    </select>
                    <InputError :message="fieldError('type')" />
                </div>
                <div class="grid gap-2">
                    <Label for="level">سطح *</Label>
                    <select
                        id="level"
                        v-model="form.level"
                        class="h-10 rounded-md border bg-background px-3 text-sm"
                        :disabled="Boolean(account?.usage_count)"
                    >
                        <option value="general">کل</option>
                        <option value="subsidiary">معین</option>
                    </select>
                    <InputError :message="fieldError('level')" />
                </div>
                <div class="grid gap-2">
                    <Label for="normal_balance">مانده طبیعی *</Label>
                    <select
                        id="normal_balance"
                        v-model="form.normal_balance"
                        class="h-10 rounded-md border bg-background px-3 text-sm"
                        :disabled="Boolean(account?.usage_count)"
                    >
                        <option value="debit">بدهکار</option>
                        <option value="credit">بستانکار</option>
                    </select>
                    <InputError :message="fieldError('normal_balance')" />
                </div>
                <div class="grid gap-2">
                    <Label for="parent_id">حساب والد</Label>
                    <select
                        id="parent_id"
                        v-model="form.parent_id"
                        class="h-10 rounded-md border bg-background px-3 text-sm"
                        :disabled="Boolean(account?.usage_count)"
                    >
                        <option :value="null">بدون والد</option>
                        <option
                            v-for="parent in parents"
                            :key="parent.id"
                            :value="parent.id"
                        >
                            {{ parent.label }}
                        </option>
                    </select>
                    <InputError :message="fieldError('parent_id')" />
                </div>
            </div>
            <div class="grid gap-2">
                <Label for="description">توضیح</Label>
                <textarea
                    id="description"
                    v-model="form.description"
                    rows="3"
                    class="rounded-md border bg-background px-3 py-2 text-sm"
                />
                <InputError :message="fieldError('description')" />
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.is_active" type="checkbox" />
                حساب فعال باشد
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.requires_party" type="checkbox" />
                ثبت طرف‌حساب در ردیف‌های این حساب الزامی است
            </label>
            <div class="flex justify-end">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-xl"
                >
                    <Spinner v-if="form.processing" />
                    <Save v-else class="size-4" />
                    ذخیره حساب
                </Button>
            </div>
        </form>
    </div>
</template>
