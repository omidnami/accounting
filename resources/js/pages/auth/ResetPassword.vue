<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/password';

defineOptions({
    layout: {
        title: 'تنظیم رمز عبور جدید',
        description: 'یک رمز عبور جدید و امن برای حساب خود انتخاب کنید.',
    },
});

const props = defineProps<{
    token: string;
    email: string;
    passwordRules: string;
}>();

const inputEmail = ref(props.email);
</script>

<template>
    <Head title="تنظیم رمز عبور جدید" />

    <Form
        v-bind="update.form()"
        :transform="(data) => ({ ...data, token, email })"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">ایمیل</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    v-model="inputEmail"
                    class="mt-1 block h-11 w-full rounded-xl bg-muted/60 px-4 text-left"
                    dir="ltr"
                    readonly
                />
                <InputError :message="errors.email" class="mt-2" />
            </div>

            <div class="grid gap-2">
                <Label for="password">رمز عبور جدید</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    class="mt-1 block h-11 w-full rounded-xl"
                    autofocus
                    placeholder="رمز عبور جدید"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">تکرار رمز عبور</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    autocomplete="new-password"
                    class="mt-1 block h-11 w-full rounded-xl"
                    placeholder="تکرار رمز عبور جدید"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-4 h-11 w-full rounded-xl"
                :disabled="processing"
                data-test="reset-password-button"
            >
                <Spinner v-if="processing" />
                ذخیره رمز عبور
            </Button>
        </div>
    </Form>
</template>
