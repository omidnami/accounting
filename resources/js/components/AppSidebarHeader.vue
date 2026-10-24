<script setup lang="ts">
import AccountMenu from '@/components/AccountMenu.vue';
import QuickAccessBar from '@/components/QuickAccessBar.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <header
        class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-2 border-b border-border/70 bg-background/80 px-5 backdrop-blur-xl transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-14 md:px-6 print:hidden"
    >
        <div class="flex shrink-0 items-center">
            <SidebarTrigger class="-ms-1" />
        </div>

        <div class="flex min-w-0 flex-1">
            <QuickAccessBar class="w-full" />
        </div>

        <AccountMenu />
    </header>
</template>
