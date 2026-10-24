import { usePage } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import {
    ArrowDown,
    ArrowUp,
    FileText,
    PackagePlus,
    Monitor,
    UserPlus,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import { create as createParty } from '@/routes/parties';
import { create as createPayment } from '@/routes/payments';
import { create as createProduct } from '@/routes/products';
import { create as createReceipt } from '@/routes/receipts';
import { index as sales } from '@/routes/sales';
import { index as pos } from '@/routes/pos';
import type { NavItem, User } from '@/types';

export type QuickAction = NavItem & {
    roles: User['role'][];
    badgeIcon?: LucideIcon;
    iconClass: string;
};

const allQuickActions: QuickAction[] = [
    {
        title: 'صندوق فروش',
        href: pos(),
        icon: Monitor,
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

export function useQuickActions() {
    const user = computed(() => usePage().props.auth.user);

    return computed(() =>
        allQuickActions.filter((action) =>
            action.roles.includes(user.value.role),
        ),
    );
}
