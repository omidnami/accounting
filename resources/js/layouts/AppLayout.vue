<script setup lang="ts">
import AppWorkspaceLayout from '@/layouts/app/AppWorkspaceLayout.vue';
import { useMenuLayout } from '@/composables/useMenuLayout';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();

const { menuLayout } = useMenuLayout();
</script>

<template>
    <AppWorkspaceLayout :menu-layout="menuLayout" :breadcrumbs="breadcrumbs">
        <slot />
    </AppWorkspaceLayout>
</template>
