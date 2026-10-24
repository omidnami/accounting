<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import {
    ArrowDown,
    ArrowUp,
    FileText,
    PackagePlus,
    Plus,
    ShoppingCart,
    UserPlus,
    Wallet,
    Zap,
} from '@lucide/vue';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { create as createParty } from '@/routes/parties';
import { create as createPayment } from '@/routes/payments';
import { create as createProduct } from '@/routes/products';
import { create as createReceipt } from '@/routes/receipts';
import { create as createSale, index as sales } from '@/routes/sales';
import type { BreadcrumbItem, NavItem, User } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

type QuickAction = NavItem & {
    roles: User['role'][];
    badgeIcon?: LucideIcon;
    iconClass: string;
};

const page = usePage();
const user = computed(() => page.props.auth.user);

const allQuickActions: QuickAction[] = [
    {
        title: 'فاکتور فروش جدید',
        href: createSale(),
        icon: ShoppingCart,
        badgeIcon: Plus,
        iconClass:
            'bg-primary/10 text-primary dark:bg-primary/15 dark:text-primary',
        roles: ['manager', 'accountant', 'cashier'],
    },
    {
        title: 'ثبت دریافت',
        href: createReceipt(),
        icon: Wallet,
        badgeIcon: ArrowDown,
        iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        roles: ['manager', 'accountant'],
    },
    {
        title: 'ثبت پرداخت',
        href: createPayment(),
        icon: Wallet,
        badgeIcon: ArrowUp,
        iconClass: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
        roles: ['manager', 'accountant'],
    },
    {
        title: 'طرف‌حساب جدید',
        href: createParty(),
        icon: UserPlus,
        iconClass: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
        roles: ['manager', 'accountant'],
    },
    {
        title: 'کالای جدید',
        href: createProduct(),
        icon: PackagePlus,
        iconClass: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
        roles: ['manager', 'accountant'],
    },
    {
        title: 'فهرست فاکتورهای فروش',
        href: sales(),
        icon: FileText,
        iconClass: 'bg-slate-500/10 text-slate-600 dark:text-slate-300',
        roles: ['manager', 'accountant', 'cashier'],
    },
];

const quickActions = computed(() =>
    allQuickActions.filter((action) => action.roles.includes(user.value.role)),
);
</script>

<template>
    <header
        class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-2 border-b border-border/70 bg-background/80 px-5 backdrop-blur-xl transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-14 md:px-6 print:hidden"
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
        <div class="mr-auto flex items-center gap-2 sm:gap-3">
            <DropdownMenu v-if="quickActions.length > 0">
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="outline"
                        size="sm"
                        class="h-8 gap-2 rounded-full border-border/70 bg-card/60 px-3 text-xs shadow-none"
                    >
                        <Zap class="size-3.5 text-primary" />
                        <span class="hidden sm:inline">دسترسی سریع</span>
                        <span class="sr-only sm:hidden">دسترسی سریع</span>
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    :side-offset="8"
                    class="w-[min(22rem,calc(100vw-2rem))] p-2"
                >
                    <div class="flex items-center justify-between px-2 py-1.5">
                        <p class="text-xs font-semibold">دسترسی سریع</p>
                        <p class="text-[10px] text-muted-foreground">
                            عملیات پرکاربرد
                        </p>
                    </div>
                    <div class="grid grid-cols-1 gap-1 sm:grid-cols-2">
                        <DropdownMenuItem
                            v-for="action in quickActions"
                            :key="action.title"
                            as-child
                            class="p-0 focus:bg-transparent"
                        >
                            <Link
                                :href="action.href"
                                class="group flex min-h-12 w-full items-center gap-3 rounded-lg px-2.5 py-2 transition-colors hover:bg-muted focus:bg-muted focus:outline-none"
                            >
                                <span
                                    class="relative grid size-9 shrink-0 place-items-center rounded-xl"
                                    :class="action.iconClass"
                                >
                                    <component
                                        :is="action.icon"
                                        class="size-4"
                                    />
                                    <span
                                        v-if="action.badgeIcon"
                                        class="absolute -bottom-1 -left-1 grid size-3.5 place-items-center rounded-full border-2 border-popover bg-popover text-foreground"
                                    >
                                        <component
                                            :is="action.badgeIcon"
                                            class="size-2.5"
                                        />
                                    </span>
                                </span>
                                <span
                                    class="min-w-0 text-xs leading-5 font-medium"
                                >
                                    {{ action.title }}
                                </span>
                            </Link>
                        </DropdownMenuItem>
                    </div>
                </DropdownMenuContent>
            </DropdownMenu>

            <span
                class="hidden items-center gap-2 rounded-full border border-border/70 bg-card/70 px-3 py-1.5 text-[10px] text-muted-foreground sm:flex"
            >
                <span class="size-1.5 rounded-full bg-primary" />
                آماده استفاده
            </span>
        </div>
    </header>
</template>
