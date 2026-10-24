<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    Mail,
    ShieldCheck,
    UserCog,
    UserPlus,
} from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    index as usersIndex,
    store as storeUser,
    update as updateUser,
} from '@/routes/users';

type Role = 'manager' | 'accountant' | 'cashier' | 'warehouse' | 'viewer';
const props = defineProps<{
    user: {
        id: number;
        name: string;
        email: string;
        role: Role;
        branch_id: number | null;
        version: number;
        is_self: boolean;
    } | null;
    branches: Array<{ id: number; code: string; name: string }>;
}>();

const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    role: props.user?.role ?? ('viewer' as Role),
    branch_id: props.user?.branch_id ?? null,
    password: '',
    password_confirmation: '',
    version: props.user?.version ?? 0,
});
const roles: Array<{
    value: Role;
    label: string;
    description: string;
}> = [
    {
        value: 'manager',
        label: 'مدیر',
        description: 'دسترسی کامل، مدیریت کاربران و Audit Log',
    },
    {
        value: 'accountant',
        label: 'حسابدار',
        description: 'عملیات مالی، تأیید اسناد و گزارش‌ها',
    },
    {
        value: 'cashier',
        label: 'صندوق‌دار',
        description: 'ساخت پیش‌نویس فروش',
    },
    {
        value: 'warehouse',
        label: 'انباردار',
        description: 'دسترسی محدود عملیات انبار',
    },
    {
        value: 'viewer',
        label: 'مشاهده‌گر',
        description: 'دسترسی محدود به اطلاعات',
    },
];
const fieldError = (key: string) =>
    (form.errors as Record<string, string>)[key];
const submit = () => {
    if (props.user) {
        form.put(updateUser(props.user.id).url, { preserveScroll: true });
        return;
    }

    form.post(storeUser().url, { preserveScroll: true });
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'کاربران', href: usersIndex() }],
    },
});
</script>

<template>
    <Head :title="user ? 'ویرایش کاربر' : 'کاربر جدید'" />

    <div class="mx-auto w-full max-w-5xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <component
                            :is="user ? UserCog : UserPlus"
                            class="size-5"
                        />
                        <span class="text-xs font-bold">مدیریت دسترسی</span>
                    </div>
                    <h1 class="text-2xl font-black sm:text-3xl">
                        {{ user ? 'ویرایش کاربر' : 'ایجاد کاربر جدید' }}
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        نقش را متناسب با مسئولیت کاربر تعیین کنید. تغییر نقش
                        بلافاصله اعمال می‌شود.
                    </p>
                </div>
                <Button as-child variant="outline" class="rounded-xl">
                    <Link :href="usersIndex()">
                        <ArrowRight class="size-4" /> بازگشت
                    </Link>
                </Button>
            </div>
        </section>

        <form
            class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_320px]"
            @submit.prevent="submit"
        >
            <div class="space-y-6">
                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="mb-4 text-sm font-bold">اطلاعات کاربر</h2>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="name">نام کاربر *</Label>
                            <Input
                                id="name"
                                v-model="form.name"
                                class="h-11 rounded-xl"
                            />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="email">ایمیل *</Label>
                            <div class="relative">
                                <Mail
                                    class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                                />
                                <Input
                                    id="email"
                                    v-model="form.email"
                                    type="email"
                                    dir="ltr"
                                    class="h-11 rounded-xl pr-9 text-left"
                                />
                            </div>
                            <InputError :message="form.errors.email" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="password">
                                {{ user ? 'رمز عبور جدید' : 'رمز عبور *' }}
                            </Label>
                            <Input
                                id="password"
                                v-model="form.password"
                                type="password"
                                class="h-11 rounded-xl"
                            />
                            <p class="text-[10px] text-muted-foreground">
                                {{
                                    user
                                        ? 'برای حفظ رمز فعلی خالی بگذارید.'
                                        : 'رمز عبور باید شرایط امنیتی اعلام‌شده را داشته باشد.'
                                }}
                            </p>
                            <InputError :message="form.errors.password" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="password_confirmation"
                                >تکرار رمز عبور</Label
                            >
                            <Input
                                id="password_confirmation"
                                v-model="form.password_confirmation"
                                type="password"
                                class="h-11 rounded-xl"
                            />
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="mb-4 text-sm font-bold">نقش کاربر</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <button
                            v-for="role in roles"
                            :key="role.value"
                            type="button"
                            class="rounded-2xl border p-4 text-right"
                            :class="
                                form.role === role.value
                                    ? 'border-primary/40 bg-primary/[.055] ring-2 ring-primary/10'
                                    : 'bg-background'
                            "
                            :disabled="user?.is_self"
                            @click="form.role = role.value"
                        >
                            <span
                                class="flex items-center gap-2 text-sm font-bold"
                            >
                                <ShieldCheck class="size-4 text-primary" />
                                {{ role.label }}
                            </span>
                            <span
                                class="mt-2 block text-[10px] leading-5 text-muted-foreground"
                            >
                                {{ role.description }}
                            </span>
                        </button>
                    </div>
                    <p
                        v-if="user?.is_self"
                        class="mt-3 text-[10px] text-amber-700 dark:text-amber-300"
                    >
                        نقش حساب کاربری خودتان قابل تغییر نیست.
                    </p>
                    <InputError :message="form.errors.role" />

                    <label class="mt-5 block">
                        <span
                            class="mb-2 flex items-center gap-2 text-sm font-bold"
                        >
                            <Building2 class="size-4 text-primary" />
                            شعبهٔ کاری
                        </span>
                        <select
                            v-model="form.branch_id"
                            class="h-11 w-full rounded-xl border bg-background px-3 text-sm"
                        >
                            <option :value="null">همه شعب</option>
                            <option
                                v-for="branch in branches"
                                :key="branch.id"
                                :value="branch.id"
                            >
                                {{ branch.name }} — {{ branch.code }}
                            </option>
                        </select>
                        <p
                            class="mt-2 text-[10px] leading-5 text-muted-foreground"
                        >
                            صندوق‌دار و انباردار باید به یک شعبه محدود شوند.
                            مدیر و حسابدار می‌توانند همهٔ شعب را داشته باشند.
                        </p>
                        <InputError
                            class="mt-1"
                            :message="form.errors.branch_id"
                        />
                    </label>
                </section>
            </div>

            <aside class="space-y-4 xl:sticky xl:top-6">
                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="text-sm font-bold">ثبت تغییرات</h2>
                    <p class="mt-2 text-[10px] leading-6 text-muted-foreground">
                        ایجاد کاربر، تغییر نقش، ایمیل و رمز عبور در Audit Log
                        ثبت می‌شود. مقدار رمز هرگز نمایش داده نمی‌شود.
                    </p>
                    <div
                        v-if="fieldError('user')"
                        class="mt-4 rounded-xl bg-red-500/10 p-3 text-[10px] text-red-700 dark:text-red-300"
                    >
                        {{ fieldError('user') }}
                    </div>
                    <Button
                        type="submit"
                        class="mt-5 h-11 w-full rounded-xl"
                        :disabled="form.processing"
                    >
                        <Spinner v-if="form.processing" />
                        <UserCog v-else class="size-4" />
                        ذخیره کاربر
                    </Button>
                </section>
            </aside>
        </form>
    </div>
</template>
