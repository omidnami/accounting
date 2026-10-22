<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const name = usePage().props.name;
</script>

<template>
    <div
        class="flex aspect-square size-9 shrink-0 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-[0_8px_24px_-12px_var(--color-primary)]"
    >
        <AppLogoIcon class="size-5 text-current" />
    </div>
    <div
        class="ms-2.5 grid flex-1 text-right group-data-[collapsible=icon]:hidden"
    >
        <span class="truncate text-sm leading-tight font-bold">{{ name }}</span>
        <span
            class="mt-0.5 truncate text-[10px] font-medium tracking-wide opacity-55"
            >مدیریت مالی فروشگاه</span
        >
    </div>
</template>
