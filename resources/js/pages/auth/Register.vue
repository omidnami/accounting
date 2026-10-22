<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineProps<{
    passwordRules: string;
}>();

defineOptions({
    layout: {
        title: 'فروشگاه شما، از اینجا شروع می‌شود',
        description: 'اطلاعات پایه حساب فروشگاه خود را وارد کنید.',
    },
});
</script>

<template>
    <Head title="ثبت‌نام" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-5">
            <div class="grid gap-2">
                <Label for="name">نام و نام خانوادگی</Label>
                <Input
                    id="name"
                    type="text"
                    required
                    v-focus
                    :tabindex="1"
                    autocomplete="name"
                    name="name"
                    placeholder="مثلاً سارا احمدی"
                    class="h-11 rounded-xl bg-background px-4"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">ایمیل</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    :tabindex="2"
                    autocomplete="email"
                    name="email"
                    placeholder="you@example.com"
                    dir="ltr"
                    class="h-11 rounded-xl bg-background px-4 text-left"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">رمز عبور</Label>
                <PasswordInput
                    id="password"
                    required
                    :tabindex="3"
                    autocomplete="new-password"
                    name="password"
                    placeholder="یک رمز عبور امن انتخاب کنید"
                    class="h-11 rounded-xl bg-background px-4"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">تکرار رمز عبور</Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    :tabindex="4"
                    autocomplete="new-password"
                    name="password_confirmation"
                    placeholder="رمز عبور را دوباره وارد کنید"
                    class="h-11 rounded-xl bg-background px-4"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-1 h-11 w-full rounded-xl text-sm shadow-md shadow-primary/10"
                tabindex="5"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                ساخت حساب فروشگاه
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            قبلاً حساب ساخته‌اید؟
            <TextLink
                :href="login()"
                class="underline underline-offset-4"
                :tabindex="6"
                >وارد شوید</TextLink
            >
        </div>
    </Form>
</template>
