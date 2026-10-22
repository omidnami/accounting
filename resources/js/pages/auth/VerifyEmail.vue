<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        title: 'تأیید ایمیل',
        description:
            'برای فعال‌سازی کامل حساب، روی لینک ارسال‌شده در ایمیل خود کلیک کنید.',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="تأیید ایمیل" />

    <div
        v-if="status === 'verification-link-sent'"
        class="mb-4 rounded-xl border border-primary/15 bg-primary/8 px-4 py-3 text-center text-sm font-medium text-primary"
    >
        لینک تأیید جدید به ایمیلی که هنگام ثبت‌نام وارد کرده‌اید ارسال شد.
    </div>

    <Form
        v-bind="send.form()"
        class="space-y-6 text-center"
        v-slot="{ processing }"
    >
        <Button :disabled="processing" variant="secondary">
            <Spinner v-if="processing" />
            ارسال دوباره لینک تأیید
        </Button>

        <TextLink :href="logout()" as="button" class="mx-auto block text-sm">
            خروج از حساب
        </TextLink>
    </Form>
</template>
