<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { BarChart3, LayoutGrid, PackageSearch, UsersRound } from '@lucide/vue';
import { computed } from 'vue';
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
import { dashboard } from '@/routes';
import { index as parties } from '@/routes/parties';
import { index as products } from '@/routes/products';
import { index as reports } from '@/routes/reports';
import type { NavItem } from '@/types';

const user = computed(() => usePage().props.auth.user);
const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'داشبورد',
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    if (user.value && ['manager', 'accountant'].includes(user.value.role)) {
        items.push({
            title: 'طرف‌حساب‌ها',
            href: parties(),
            icon: UsersRound,
        });
        items.push({
            title: 'کالا و موجودی',
            href: products(),
            icon: PackageSearch,
        });
        items.push({
            title: 'گزارش‌های مالی',
            href: reports(),
            icon: BarChart3,
        });
    }

    return items;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
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
