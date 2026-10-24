<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import {
    Activity,
    ArrowDown,
    ArrowUp,
    ChevronDown,
    FileText,
    LogOut,
    PackagePlus,
    Plus,
    ShoppingCart,
    SlidersHorizontal,
    UserPlus,
    UserRoundPen,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import UserInfo from '@/components/UserInfo.vue';
import { logout } from '@/routes';
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
        title: 'فاکتور',
        href: createSale(),
        icon: ShoppingCart,
        badgeIcon: Plus,
        iconClass:
            'bg-primary/10 text-primary dark:bg-primary/15 dark:text-primary',
        roles: ['manager', 'accountant', 'cashier'],
    },
    {
        title: 'دریافت',
        href: createReceipt(),
        icon: Wallet,
        badgeIcon: ArrowDown,
        iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        roles: ['manager', 'accountant'],
    },
    {
        title: 'پرداخت',
        href: createPayment(),
        icon: Wallet,
        badgeIcon: ArrowUp,
        iconClass: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
        roles: ['manager', 'accountant'],
    },
    {
        title: 'اشخاص',
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
        title: 'فاکتورها',
        href: sales(),
        icon: FileText,
        iconClass: 'bg-slate-500/10 text-slate-600 dark:text-slate-300',
        roles: ['manager', 'accountant', 'cashier'],
    },
];

const quickActions = computed(() =>
    allQuickActions.filter((action) => action.roles.includes(user.value.role)),
);

const accountActions = [
    {
        title: 'ویرایش پروفایل',
        icon: UserRoundPen,
    },
    {
        title: 'تنظیمات شخصی',
        icon: SlidersHorizontal,
    },
    {
        title: 'آخرین فعالیت من',
        icon: Activity,
    },
];

const handleLogout = () => {
    router.flushAll();
};
</script>

<template>
    <header
        class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-2 border-b border-border/70 bg-background/80 px-5 backdrop-blur-xl transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-14 md:px-6 print:hidden"
    >
        <div class="flex shrink-0 items-center">
            <SidebarTrigger class="-ms-1" />
        </div>

        <nav
            v-if="quickActions.length > 0"
            aria-label="دسترسی سریع"
            class="-mx-1 flex min-w-0 flex-1 [scrollbar-width:none] items-center overflow-x-auto px-1 [&::-webkit-scrollbar]:hidden"
        >
            <div class="flex w-max items-center gap-2 sm:gap-3">
                <Link
                    v-for="action in quickActions"
                    :key="action.title"
                    :href="action.href"
                    :title="action.title"
                    class="group flex shrink-0 flex-col items-center justify-center gap-1 rounded-lg px-2 py-1 transition-colors hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                >
                    <span
                        class="relative grid size-8 shrink-0 place-items-center rounded-lg"
                        :class="action.iconClass"
                    >
                        <component :is="action.icon" class="size-4" />
                        <span
                            v-if="action.badgeIcon"
                            class="absolute -bottom-1 -left-1 grid size-3.5 place-items-center rounded-full border-2 border-background bg-background text-foreground"
                        >
                            <component
                                :is="action.badgeIcon"
                                class="size-2.5"
                            />
                        </span>
                    </span>
                    <span
                        class="text-[10px] leading-4 font-medium whitespace-nowrap text-muted-foreground transition-colors group-hover:text-foreground"
                    >
                        {{ action.title }}
                    </span>
                </Link>
            </div>
        </nav>

        <div v-else class="flex-1" />

        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    dir="rtl"
                    class="flex h-10 shrink-0 items-center gap-2 bg-transparent px-1 text-right focus-visible:outline-none"
                >
                    <UserInfo :user="user" />
                    <ChevronDown class="size-3.5 text-muted-foreground" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                :side-offset="8"
                class="w-56 rounded-xl p-1.5"
                dir="rtl"
            >
                <DropdownMenuItem
                    v-for="action in accountActions"
                    :key="action.title"
                    as-child
                >
                    <a
                        href="#"
                        dir="rtl"
                        class="flex w-full cursor-pointer items-center gap-2.5 px-2 py-2 text-right"
                        @click.prevent
                    >
                        <component
                            :is="action.icon"
                            class="size-4 text-muted-foreground"
                        />
                        <span>{{ action.title }}</span>
                    </a>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem as-child>
                    <Link
                        :href="logout()"
                        as="button"
                        dir="rtl"
                        class="flex w-full cursor-pointer items-center gap-2.5 px-2 py-2 text-right text-destructive"
                        data-test="header-logout-button"
                        @click="handleLogout"
                    >
                        <LogOut class="size-4" />
                        <span>خروج از سیستم</span>
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </header>
</template>
