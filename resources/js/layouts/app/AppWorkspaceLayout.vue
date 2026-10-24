<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import AppTopbarHeader from '@/components/AppTopbarHeader.vue';
import QuickAccessBar from '@/components/QuickAccessBar.vue';
import QuickAccessRail from '@/components/QuickAccessRail.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem, MenuLayout } from '@/types';

withDefaults(
    defineProps<{
        menuLayout: MenuLayout;
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar v-if="menuLayout === 'sidebar'" />
        <AppContent
            variant="sidebar"
            class="min-w-0 overflow-x-clip print:m-0 print:rounded-none print:p-0 print:shadow-none"
        >
            <AppTopbarHeader
                v-if="menuLayout === 'topbar'"
                :breadcrumbs="breadcrumbs"
            />
            <AppSidebarHeader v-else :breadcrumbs="breadcrumbs" />
            <QuickAccessBar
                v-if="menuLayout === 'topbar'"
                class="mx-0 border-b border-border/70 px-4 py-2 lg:hidden print:hidden"
            />
            <div class="flex min-w-0 flex-1">
                <QuickAccessRail v-if="menuLayout === 'topbar'" />
                <!-- Keep the page in the same component tree when menus change. -->
                <div class="min-w-0 flex-1">
                    <slot />
                </div>
            </div>
        </AppContent>
        <Toaster />
    </AppShell>
</template>
