<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'تنظیمات ظاهر',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="تنظیمات ظاهر" />

    <h1 class="sr-only">تنظیمات ظاهر</h1>

    <div
        class="space-y-6 rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:p-6"
    >
        <Heading
            variant="small"
            title="ظاهر فضای کاری"
            description="حالت نمایش مناسب خود را انتخاب کنید."
        />
        <AppearanceTabs />
    </div>
</template>
