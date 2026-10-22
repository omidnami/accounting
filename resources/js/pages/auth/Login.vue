<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import PasskeyVerify from '@/components/PasskeyVerify.vue';

defineOptions({
    layout: {
        title: 'خوش برگشتید',
        description: 'برای ادامه وارد حساب کاربری فروشگاه خود شوید.',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();
</script>

<template>
    <Head title="ورود" />

    <div
        v-if="status"
        class="mb-4 rounded-xl border border-primary/15 bg-primary/8 px-4 py-3 text-center text-sm font-medium text-primary"
    >
        {{ status }}
    </div>

    <PasskeyVerify />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-5">
            <div class="grid gap-2">
                <Label for="email">ایمیل</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    v-focus
                    :tabindex="1"
                    autocomplete="email"
                    placeholder="you@example.com"
                    dir="ltr"
                    class="h-11 rounded-xl bg-background px-4 text-left"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">رمز عبور</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-sm"
                        :tabindex="5"
                    >
                        فراموش کرده‌اید؟
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="رمز عبور خود را وارد کنید"
                    class="h-11 rounded-xl bg-background px-4"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label
                    for="remember"
                    class="flex cursor-pointer items-center gap-2.5 text-sm font-normal text-muted-foreground"
                >
                    <Checkbox id="remember" name="remember" :tabindex="3" />
                    <span>مرا به خاطر بسپار</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-1 h-11 w-full rounded-xl text-sm shadow-md shadow-primary/10"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                ورود به حساب
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            حساب کاربری ندارید؟
            <TextLink :href="register()" :tabindex="5">ثبت‌نام کنید</TextLink>
        </div>
    </Form>
</template>
