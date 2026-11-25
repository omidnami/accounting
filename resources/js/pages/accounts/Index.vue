<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Plus } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { create, edit, index } from '@/routes/accounts';

type AccountRow = {
    id: number;
    code: string;
    name: string;
    type: string;
    level: string;
    normal_balance: string;
    parent_name: string | null;
    is_active: boolean;
    children_count: number;
    usage_count: number;
    can_manage: boolean;
};

defineProps<{ accounts: AccountRow[] }>();

const typeLabels: Record<string, string> = {
    asset: 'دارایی',
    liability: 'بدهی',
    equity: 'حقوق مالکانه',
    revenue: 'درآمد',
    expense: 'هزینه',
};
const levelLabels: Record<string, string> = {
    general: 'کل',
    subsidiary: 'معین',
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'درخت حساب', href: index() }],
    },
});
</script>

<template>
    <Head title="درخت حساب" />
    <div class="mx-auto w-full max-w-6xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-black sm:text-3xl">درخت حساب</h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    ساختار حساب‌ها پس از استفاده قفل می‌شود؛ نام، توضیح و وضعیت
                    قابل ویرایش است.
                </p>
            </div>
            <Button as-child v-if="accounts[0]?.can_manage" class="rounded-xl">
                <Link :href="create()">
                    <Plus class="size-4" /> حساب جدید
                </Link>
            </Button>
        </section>

        <section class="overflow-hidden rounded-2xl border bg-card">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-right text-xs">
                    <thead class="bg-muted/40 text-[11px]">
                        <tr>
                            <th class="px-5 py-3">کد</th>
                            <th class="px-4 py-3">نام حساب</th>
                            <th class="px-4 py-3">ماهیت</th>
                            <th class="px-4 py-3">سطح</th>
                            <th class="px-4 py-3">والد</th>
                            <th class="px-4 py-3">وضعیت</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="account in accounts" :key="account.id">
                            <td class="px-5 py-3 font-mono" dir="ltr">
                                {{ account.code }}
                            </td>
                            <td class="px-4 py-3 font-bold">
                                {{ account.name }}
                            </td>
                            <td class="px-4 py-3">
                                {{ typeLabels[account.type] ?? account.type }}
                            </td>
                            <td class="px-4 py-3">
                                {{
                                    levelLabels[account.level] ?? account.level
                                }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ account.parent_name ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2.5 py-1 text-[10px] font-semibold"
                                    :class="
                                        account.is_active
                                            ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                >
                                    {{ account.is_active ? 'فعال' : 'غیرفعال' }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-left">
                                <Button
                                    v-if="account.can_manage"
                                    as-child
                                    variant="ghost"
                                    size="sm"
                                >
                                    <Link :href="edit(account.id)">
                                        <ArrowRight class="size-4" /> ویرایش
                                    </Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
