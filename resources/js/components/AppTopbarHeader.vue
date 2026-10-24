<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AccountMenu from '@/components/AccountMenu.vue';
import AppLogo from '@/components/AppLogo.vue';
import TopbarMobileNavigation from '@/components/TopbarMobileNavigation.vue';
import TopNavigation from '@/components/TopNavigation.vue';
import { useMainNavigation } from '@/composables/useMainNavigation';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const mainNavItems = useMainNavigation({ groupAccountOperations: true });
</script>

<template>
    <header
        class="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-3 border-b border-border/70 bg-background/85 px-4 backdrop-blur-xl md:px-5 print:hidden"
    >
        <TopbarMobileNavigation :items="mainNavItems" />

        <Link
            :href="dashboard()"
            class="flex min-w-0 flex-1 items-center lg:flex-none lg:shrink-0 [&>div:last-child]:min-w-0"
        >
            <AppLogo />
        </Link>

        <TopNavigation :items="mainNavItems" />

        <AccountMenu />
    </header>
</template>
