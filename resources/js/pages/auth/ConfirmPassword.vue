<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import PasskeyVerify from '@/components/PasskeyVerify.vue';

defineOptions({
    layout: {
        title: 'تأیید رمز عبور',
        description:
            'برای ورود به بخش امن حساب، لطفاً رمز عبور خود را دوباره وارد کنید.',
    },
});
</script>

<template>
    <Head title="تأیید رمز عبور" />

    <PasskeyVerify
        :routes="{
            options: confirmOptions(),
            submit: confirmStore(),
        }"
        label="تأیید با کلید عبور"
        loading-label="در حال تأیید..."
        separator="یا با رمز عبور تأیید کنید"
    />

    <Form
        v-bind="store.form()"
        reset-on-success
        v-slot="{ errors, processing }"
    >
        <div class="space-y-6">
            <div class="grid gap-2">
                <Label htmlFor="password">رمز عبور</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block h-11 w-full rounded-xl"
                    required
                    autocomplete="current-password"
                    autofocus
                />

                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center">
                <Button
                    class="h-11 w-full rounded-xl"
                    :disabled="processing"
                    data-test="confirm-password-button"
                >
                    <Spinner v-if="processing" />
                    تأیید رمز عبور
                </Button>
            </div>
        </div>
    </Form>
</template>
