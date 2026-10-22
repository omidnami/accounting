<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineOptions({
    layout: {
        title: 'بازیابی رمز عبور',
        description:
            'ایمیل حساب خود را وارد کنید تا لینک تنظیم رمز جدید ارسال شود.',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="بازیابی رمز عبور" />

    <div
        v-if="status"
        class="mb-4 rounded-xl border border-primary/15 bg-primary/8 px-4 py-3 text-center text-sm font-medium text-primary"
    >
        {{ status }}
    </div>

    <div class="space-y-6">
        <Form v-bind="email.form()" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="email">ایمیل</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="off"
                    v-focus
                    placeholder="you@example.com"
                    dir="ltr"
                    class="h-11 rounded-xl bg-background px-4 text-left"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="my-6 flex items-center justify-start">
                <Button
                    class="h-11 w-full rounded-xl"
                    :disabled="processing"
                    data-test="email-password-reset-link-button"
                >
                    <Spinner v-if="processing" />
                    ارسال لینک بازیابی
                </Button>
            </div>
        </Form>

        <div class="space-x-1 text-center text-sm text-muted-foreground">
            <span>یا برگردید به</span>
            <TextLink :href="login()">صفحه ورود</TextLink>
        </div>
    </div>
</template>
