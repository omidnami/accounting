import { usePage } from '@inertiajs/vue3';
import {
    BarChart3,
    BookOpen,
    LayoutGrid,
    Landmark,
    PackageSearch,
    ShoppingBag,
    ShoppingCart,
    UsersRound,
    UserCog,
} from '@lucide/vue';
import { computed } from 'vue';
import { dashboard } from '@/routes';
import { index as auditLogs } from '@/routes/audit-logs';
import { index as checks } from '@/routes/checks';
import { index as fiscalYears } from '@/routes/fiscal-years';
import { cardex as inventoryCardex } from '@/routes/inventory';
import { index as inventoryTransfers } from '@/routes/inventory-transfers';
import { index as journals } from '@/routes/journals';
import { index as parties } from '@/routes/parties';
import { index as payments } from '@/routes/payments';
import { index as productCategories } from '@/routes/product-categories';
import { index as products } from '@/routes/products';
import { edit as printSettings } from '@/routes/print-settings';
import { index as purchases } from '@/routes/purchases';
import { index as receipts } from '@/routes/receipts';
import {
    analytics as analyticsReport,
    index as reports,
    operations as operationalReports,
} from '@/routes/reports';
import { index as sales } from '@/routes/sales';
import { index as stockCounts } from '@/routes/stock-counts';
import { index as treasuryAccounts } from '@/routes/treasury-accounts';
import { index as users } from '@/routes/users';
import type { NavEntry, NavItem } from '@/types';

export function useMainNavigation() {
    const user = computed(() => usePage().props.auth.user);

    return computed<NavEntry[]>(() => {
        const items: NavEntry[] = [
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
        }

        const inventoryItems: NavItem[] = [];

        if (user.value && ['manager', 'accountant'].includes(user.value.role)) {
            inventoryItems.push(
                {
                    title: 'کالا و موجودی',
                    href: products(),
                },
                {
                    title: 'دسته‌بندی کالا',
                    href: productCategories(),
                },
            );
        }

        if (
            user.value &&
            ['manager', 'accountant', 'warehouse'].includes(user.value.role)
        ) {
            inventoryItems.push(
                {
                    title: 'کاردکس موجودی',
                    href: inventoryCardex(),
                },
                {
                    title: 'شمارش و تعدیل',
                    href: stockCounts(),
                },
                {
                    title: 'انتقال انبار',
                    href: inventoryTransfers(),
                },
            );
        }

        if (inventoryItems.length > 0) {
            items.push({
                title: 'کالا و انبار',
                icon: PackageSearch,
                children: inventoryItems,
            });
        }

        if (user.value && ['manager', 'accountant'].includes(user.value.role)) {
            items.push({
                title: 'خرید و تأمین',
                href: purchases(),
                icon: ShoppingBag,
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
            items.push(
                {
                    title: 'خزانه‌داری',
                    icon: Landmark,
                    children: [
                        {
                            title: 'دریافت‌ها',
                            href: receipts(),
                        },
                        {
                            title: 'پرداخت‌ها',
                            href: payments(),
                        },
                        {
                            title: 'چک‌ها',
                            href: checks(),
                        },
                        {
                            title: 'حساب‌های خزانه',
                            href: treasuryAccounts(),
                        },
                    ],
                },
                {
                    title: 'حسابداری',
                    icon: BookOpen,
                    children: [
                        {
                            title: 'اسناد دستی',
                            href: journals(),
                        },
                        {
                            title: 'سال‌های مالی',
                            href: fiscalYears(),
                        },
                    ],
                },
                {
                    title: 'گزارش‌ها',
                    icon: BarChart3,
                    children: [
                        {
                            title: 'گزارش‌های مالی',
                            href: reports(),
                        },
                        {
                            title: 'گزارش‌های عملیاتی',
                            href: operationalReports(),
                        },
                        {
                            title: 'تحلیل کالا و Batch',
                            href: analyticsReport(),
                        },
                    ],
                },
            );
        }

        if (user.value?.role === 'manager') {
            items.push({
                title: 'مدیریت سیستم',
                icon: UserCog,
                children: [
                    {
                        title: 'تنظیمات چاپ',
                        href: printSettings(),
                    },
                    {
                        title: 'کاربران',
                        href: users(),
                    },
                    {
                        title: 'گزارش رویدادها',
                        href: auditLogs(),
                    },
                ],
            });
        }

        return items;
    });
}
