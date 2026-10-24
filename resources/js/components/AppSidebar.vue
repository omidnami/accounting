<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useMainNavigation } from '@/composables/useMainNavigation';
import { dashboard } from '@/routes';

const mainNavItems = useMainNavigation();
</script>

<template>
    <Sidebar
        side="right"
        collapsible="icon"
        variant="inset"
        class="print:hidden"
    >
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <div
                class="mx-2 mb-2 rounded-xl border border-sidebar-border/70 bg-sidebar-accent/40 px-3 py-2.5 group-data-[collapsible=icon]:hidden"
            >
                <p class="text-[11px] font-semibold text-sidebar-foreground/85">
                    حساب‌یار، نسخه ۱.۰
                </p>
                <p class="mt-1 text-[10px] text-sidebar-foreground/45">
                    پایه‌گذاری یک مدیریت مالی دقیق
                </p>
            </div>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
