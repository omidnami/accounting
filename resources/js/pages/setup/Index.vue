<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/setup';

const props = defineProps<{
    passwordRules: string;
    setupTokenRequired: boolean;
}>();
const form = useForm({
    store_name: '',
    admin_name: '',
    email: '',
    password: '',
    password_confirmation: '',
    timezone: 'Asia/Tehran',
    currency: 'IRR',
});
const fieldError = (key: string) =>
    (form.errors as Record<string, string>)[key];
</script>

<template>
    <Head title="راه‌اندازی اولیه" />
    <div class="mx-auto w-full max-w-2xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section>
            <h1 class="text-2xl font-black sm:text-3xl">راه‌اندازی اولیه</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                این فرم فقط زمانی در دسترس است که هیچ کاربری در سیستم وجود
                ندارد. پس از ثبت، دسترسی مدیر اول ایجاد می‌شود.
            </p>
            <p
                v-if="setupTokenRequired"
                class="mt-2 text-xs text-amber-700 dark:text-amber-300"
            >
                برای باز کردن این صفحه باید توکن راه‌اندازی در URL وجود داشته
                باشد.
            </p>
        </section>
        <form
            class="space-y-5 rounded-2xl border bg-card p-5 sm:p-6"
            @submit.prevent="form.post(store().url)"
        >
            <div
                v-if="fieldError('setup')"
                class="rounded-xl bg-red-500/10 p-3 text-xs text-red-700 dark:text-red-300"
            >
                {{ fieldError('setup') }}
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="store_name">نام فروشگاه *</Label>
                    <Input id="store_name" v-model="form.store_name" />
                    <InputError :message="fieldError('store_name')" />
                </div>
                <div class="grid gap-2">
                    <Label for="admin_name">نام مدیر *</Label>
                    <Input id="admin_name" v-model="form.admin_name" />
                    <InputError :message="fieldError('admin_name')" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="email">ایمیل مدیر *</Label>
                    <Input
                        id="email"
                        v-model="form.email"
                        type="email"
                        dir="ltr"
                    />
                    <InputError :message="fieldError('email')" />
                </div>
                <div class="grid gap-2">
                    <Label for="password">رمز عبور *</Label>
                    <Input
                        id="password"
                        v-model="form.password"
                        type="password"
                    />
                    <p class="text-[10px] text-muted-foreground">
                        {{ passwordRules }}
                    </p>
                    <InputError :message="fieldError('password')" />
                </div>
                <div class="grid gap-2">
                    <Label for="password_confirmation">تکرار رمز عبور *</Label>
                    <Input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="timezone">منطقه زمانی</Label>
                    <select
                        id="timezone"
                        v-model="form.timezone"
                        class="h-10 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="Asia/Tehran">تهران</option>
                        <option value="UTC">UTC</option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label for="currency">واحد پول</Label>
                    <select
                        id="currency"
                        v-model="form.currency"
                        class="h-10 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="IRR">ریال</option>
                        <option value="IRT">تومان</option>
                    </select>
                </div>
            </div>
            <div class="flex justify-end">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-xl"
                >
                    <Spinner v-if="form.processing" />
                    <Save v-else class="size-4" />
                    ایجاد مدیر و فروشگاه
                </Button>
            </div>
        </form>
    </div>
</template>
