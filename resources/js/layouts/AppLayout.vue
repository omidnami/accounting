<script setup lang="ts">
import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.vue';
import AppTopbarLayout from '@/layouts/app/AppTopbarLayout.vue';
import { useMenuLayout } from '@/composables/useMenuLayout';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();

const { menuLayout } = useMenuLayout();
</script>

<template>
    <AppTopbarLayout v-if="menuLayout === 'topbar'" :breadcrumbs="breadcrumbs">
        <slot />
    </AppTopbarLayout>
    <AppSidebarLayout v-else :breadcrumbs="breadcrumbs">
        <slot />
    </AppSidebarLayout>
</template>
