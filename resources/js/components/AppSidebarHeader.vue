<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
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
        class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-2 border-b border-border/70 bg-background/80 px-5 backdrop-blur-xl transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-14 md:px-6"
    >
        <div class="flex items-center gap-3">
            <SidebarTrigger class="-ms-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
            <span v-else class="text-sm font-semibold"
                >مرکز مدیریت فروشگاه</span
            >
        </div>
        <span
            class="mr-auto hidden items-center gap-2 rounded-full border border-border/70 bg-card/70 px-3 py-1.5 text-[10px] text-muted-foreground sm:flex"
        >
            <span class="size-1.5 rounded-full bg-primary" />
            آماده استفاده
        </span>
    </header>
</template>
