<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppTopbarHeader from '@/components/AppTopbarHeader.vue';
import QuickAccessRail from '@/components/QuickAccessRail.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="header">
        <AppTopbarHeader :breadcrumbs="breadcrumbs" />
        <div class="flex min-h-[calc(100svh-4rem)] w-full">
            <QuickAccessRail />
            <AppContent
                variant="header"
                class="min-w-0 overflow-x-clip print:m-0 print:max-w-none print:p-0"
            >
                <slot />
            </AppContent>
        </div>
        <Toaster />
    </AppShell>
</template>
