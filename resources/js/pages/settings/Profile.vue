<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'تنظیمات پروفایل',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="تنظیمات پروفایل" />

    <h1 class="sr-only">تنظیمات پروفایل</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="اطلاعات شخصی"
            description="نام و آدرس ایمیل حساب خود را به‌روزرسانی کنید."
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-5 rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:p-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">نام و نام خانوادگی</Label>
                <Input
                    id="name"
                    class="mt-1 block h-11 w-full rounded-xl px-4"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="نام کامل"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">ایمیل</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block h-11 w-full rounded-xl px-4 text-left"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="you@example.com"
                    dir="ltr"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="-mt-4 text-sm text-muted-foreground">
                    آدرس ایمیل شما هنوز تأیید نشده است.
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        برای ارسال دوباره ایمیل تأیید کلیک کنید.
                    </Link>
                </p>

                <div
                    v-if="page.props.status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    لینک تأیید جدید به ایمیل شما ارسال شد.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-profile-button"
                    class="h-10 rounded-xl px-5"
                    >ذخیره تغییرات</Button
                >
            </div>
        </Form>
    </div>

    <DeleteUser />
</template>
