<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BarChart3,
    ArrowLeftRight,
    BookOpen,
    CalendarDays,
    ClipboardCheck,
    CircleDollarSign,
    HandCoins,
    History,
    LayoutGrid,
    Landmark,
    PackageSearch,
    ScrollText,
    ShoppingBag,
    ShoppingCart,
    UsersRound,
    UserCog,
    WalletCards,
} from '@lucide/vue';
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
import { index as checks } from '@/routes/checks';
import { index as treasuryAccounts } from '@/routes/treasury-accounts';
import { index as auditLogs } from '@/routes/audit-logs';
import { index as users } from '@/routes/users';
import { index as fiscalYears } from '@/routes/fiscal-years';
import { index as parties } from '@/routes/parties';
import { index as products } from '@/routes/products';
import { cardex as inventoryCardex } from '@/routes/inventory';
import { index as inventoryTransfers } from '@/routes/inventory-transfers';
import { index as stockCounts } from '@/routes/stock-counts';
import { index as payments } from '@/routes/payments';
import { index as purchases } from '@/routes/purchases';
import { index as reports } from '@/routes/reports';
import { index as journals } from '@/routes/journals';
import { index as receipts } from '@/routes/receipts';
import { index as sales } from '@/routes/sales';
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
            title: 'خرید و تأمین',
            href: purchases(),
            icon: ShoppingBag,
        });
        items.push({
            title: 'گزارش‌های مالی',
            href: reports(),
            icon: BarChart3,
        });
        items.push({
            title: 'سال‌های مالی',
            href: fiscalYears(),
            icon: CalendarDays,
        });
        items.push({
            title: 'اسناد دستی',
            href: journals(),
            icon: BookOpen,
        });
    }

    if (
        user.value &&
        ['manager', 'accountant', 'warehouse'].includes(user.value.role)
    ) {
        items.push({
            title: 'کاردکس موجودی',
            href: inventoryCardex(),
            icon: ScrollText,
        });
        items.push({
            title: 'شمارش و تعدیل',
            href: stockCounts(),
            icon: ClipboardCheck,
        });
        items.push({
            title: 'انتقال انبار',
            href: inventoryTransfers(),
            icon: ArrowLeftRight,
        });
    }

    if (
        user.value &&
        ['manager', 'accountant', 'cashier'].includes(user.value.role)
    ) {
        items.push({
            title: 'فروش و صندوق',
            href: sales(),
            icon: ShoppingCart,
        });
    }

    if (user.value && ['manager', 'accountant'].includes(user.value.role)) {
        items.push({
            title: 'دریافت‌ها',
            href: receipts(),
            icon: HandCoins,
        });
        items.push({
            title: 'پرداخت‌ها',
            href: payments(),
            icon: CircleDollarSign,
        });
        items.push({
            title: 'چک‌ها',
            href: checks(),
            icon: WalletCards,
        });
        items.push({
            title: 'حساب‌های خزانه',
            href: treasuryAccounts(),
            icon: Landmark,
        });
    }

    if (user.value?.role === 'manager') {
        items.push({
            title: 'کاربران',
            href: users(),
            icon: UserCog,
        });
        items.push({
            title: 'گزارش رویدادها',
            href: auditLogs(),
            icon: History,
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
