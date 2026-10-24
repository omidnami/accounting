<script setup lang="ts">
import AppWorkspaceLayout from '@/layouts/app/AppWorkspaceLayout.vue';
import type { BreadcrumbItem } from '@/types';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItem[] }>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppWorkspaceLayout menu-layout="topbar" :breadcrumbs="breadcrumbs">
        <slot />
    </AppWorkspaceLayout>
</template>
