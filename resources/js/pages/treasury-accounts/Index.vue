<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Banknote,
    CheckCircle2,
    CircleOff,
    CreditCard,
    FilterX,
    Landmark,
    PencilLine,
    Plus,
    Search,
    WalletCards,
} from '@lucide/vue';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import {
    create as createTreasuryAccount,
    edit as editTreasuryAccount,
    index as treasuryAccountsIndex,
    status as updateTreasuryAccountStatus,
} from '@/routes/treasury-accounts';

type AccountType = 'cash' | 'bank' | 'card';

type AccountRow = {
    id: number;
    code: string;
    name: string;
    type: AccountType;
    is_default: boolean;
    is_active: boolean;
    version: number;
    gl_account: {
        code: string;
        name: string;
    };
    pending_documents: number;
};

const props = defineProps<{
    accounts: AccountRow[];
    filters: {
        search: string;
        type: AccountType | null;
        status: 'active' | 'inactive' | null;
    };
    summary: {
        total: number;
        active: number;
        cash: number;
        bank: number;
    };
    branch: {
        name: string;
        code: string;
    };
}>();

const filters = reactive({
    search: props.filters.search,
    type: props.filters.type ?? '',
    status: props.filters.status ?? '',
});
const statusForm = useForm({ is_active: true, version: 0 });
const count = new Intl.NumberFormat('fa-IR');
const typeMeta: Record<
    AccountType,
    { label: string; icon: typeof Banknote; className: string }
> = {
    cash: {
        label: 'صندوق نقدی',
        icon: Banknote,
        className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    },
    bank: {
        label: 'حساب بانکی',
        icon: Landmark,
        className: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
    },
    card: {
        label: 'حساب کارتی',
        icon: CreditCard,
        className: 'bg-violet-500/10 text-violet-700 dark:text-violet-300',
    },
};
const fieldError = (key: string) =>
    (statusForm.errors as Record<string, string>)[key];

const applyFilters = () => {
    router.get(
        treasuryAccountsIndex(),
        {
            search: filters.search.trim() || undefined,
            type: filters.type || undefined,
            status: filters.status || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
const resetFilters = () => {
    filters.search = '';
    filters.type = '';
    filters.status = '';
    router.get(treasuryAccountsIndex(), {}, { preserveScroll: true });
};
const toggleStatus = (account: AccountRow) => {
    if (
        account.is_active &&
        !window.confirm(`حساب «${account.name}» غیرفعال شود؟`)
    ) {
        return;
    }

    statusForm
        .transform(() => ({
            is_active: !account.is_active,
            version: account.version,
        }))
        .patch(updateTreasuryAccountStatus(account.id).url, {
            preserveScroll: true,
        });
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'حساب‌های خزانه',
                href: treasuryAccountsIndex(),
            },
        ],
    },
});
</script>

<template>
    <Head title="حساب‌های خزانه" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="relative overflow-hidden rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="relative flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <WalletCards class="size-5" />
                        <span class="text-xs font-bold">حساب‌های خزانه</span>
                    </div>
                    <h1 class="text-2xl font-black tracking-tight sm:text-3xl">
                        صندوق، بانک و کارت در یک ساختار کنترل‌شده
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        شعبه {{ branch.name }} · هر حساب به یک حساب معین دارایی
                        در دفتر کل متصل است.
                    </p>
                </div>
                <Button as-child class="h-11 rounded-xl px-5 shadow-md">
                    <Link :href="createTreasuryAccount()">
                        <Plus class="size-4" />
                        حساب خزانه جدید
                    </Link>
                </Button>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-2xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">کل حساب‌ها</p>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.total) }}
                </p>
            </article>
            <article class="rounded-2xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">حساب‌های فعال</p>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.active) }}
                </p>
            </article>
            <article class="rounded-2xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">صندوق‌های فعال</p>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.cash) }}
                </p>
            </article>
            <article class="rounded-2xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">بانک‌های فعال</p>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.bank) }}
                </p>
            </article>
        </section>

        <form
            class="grid gap-3 rounded-2xl border bg-card p-4 md:grid-cols-[minmax(0,1fr)_180px_180px_auto]"
            @submit.prevent="applyFilters"
        >
            <label class="relative">
                <Search
                    class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                />
                <input
                    v-model="filters.search"
                    type="search"
                    placeholder="کد، نام یا حساب دفتر کل..."
                    class="h-10 w-full rounded-xl border bg-background pr-9 pl-3 text-sm"
                />
            </label>
            <select
                v-model="filters.type"
                class="h-10 rounded-xl border bg-background px-3 text-sm"
            >
                <option value="">همه انواع</option>
                <option value="cash">صندوق</option>
                <option value="bank">بانک</option>
                <option value="card">کارت</option>
            </select>
            <select
                v-model="filters.status"
                class="h-10 rounded-xl border bg-background px-3 text-sm"
            >
                <option value="">همه وضعیت‌ها</option>
                <option value="active">فعال</option>
                <option value="inactive">غیرفعال</option>
            </select>
            <div class="flex gap-2">
                <Button type="submit" class="h-10 flex-1 rounded-xl">
                    <Search class="size-4" /> فیلتر
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    class="size-10"
                    @click="resetFilters"
                >
                    <FilterX class="size-4" />
                </Button>
            </div>
        </form>

        <div
            v-if="fieldError('treasury_account')"
            class="rounded-xl border border-red-500/20 bg-red-500/10 px-4 py-3 text-xs text-red-700 dark:text-red-300"
        >
            {{ fieldError('treasury_account') }}
        </div>

        <section class="overflow-hidden rounded-2xl border bg-card">
            <div class="border-b px-5 py-4">
                <h2 class="text-sm font-bold">حساب‌های شعبه</h2>
                <p class="mt-1 text-[10px] text-muted-foreground">
                    {{ count.format(accounts.length) }} حساب
                </p>
            </div>
            <div v-if="accounts.length" class="overflow-x-auto">
                <table class="w-full min-w-[880px] text-right">
                    <thead class="bg-muted/35 text-[11px]">
                        <tr>
                            <th class="px-5 py-3">حساب</th>
                            <th class="px-4 py-3">نوع</th>
                            <th class="px-4 py-3">حساب دفتر کل</th>
                            <th class="px-4 py-3">پیش‌فرض</th>
                            <th class="px-4 py-3">در انتظار</th>
                            <th class="px-4 py-3">وضعیت</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="account in accounts" :key="account.id">
                            <td class="px-5 py-4">
                                <p class="text-xs font-bold">
                                    {{ account.name }}
                                </p>
                                <p
                                    dir="ltr"
                                    class="mt-1 text-right font-mono text-[9px] text-muted-foreground"
                                >
                                    {{ account.code }}
                                </p>
                            </td>
                            <td class="px-4 py-4">
                                <span
                                    class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-semibold"
                                    :class="typeMeta[account.type].className"
                                >
                                    <component
                                        :is="typeMeta[account.type].icon"
                                        class="size-3"
                                    />
                                    {{ typeMeta[account.type].label }}
                                </span>
                            </td>
                            <td class="px-4 py-4 text-xs">
                                {{ account.gl_account.name }}
                                <span
                                    dir="ltr"
                                    class="mt-1 block text-right font-mono text-[9px] text-muted-foreground"
                                >
                                    {{ account.gl_account.code }}
                                </span>
                            </td>
                            <td class="px-4 py-4 text-xs">
                                <span
                                    v-if="account.is_default"
                                    class="text-primary"
                                >
                                    پیش‌فرض
                                </span>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-4 py-4 text-xs">
                                {{ count.format(account.pending_documents) }}
                            </td>
                            <td class="px-4 py-4">
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
                            <td class="px-5 py-4">
                                <div class="flex justify-end gap-1">
                                    <Button as-child variant="ghost" size="sm">
                                        <Link
                                            :href="
                                                editTreasuryAccount(account.id)
                                            "
                                        >
                                            <PencilLine class="size-4" />
                                            ویرایش
                                        </Link>
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        :disabled="statusForm.processing"
                                        @click="toggleStatus(account)"
                                    >
                                        <CheckCircle2
                                            v-if="!account.is_active"
                                            class="size-4"
                                        />
                                        <CircleOff v-else class="size-4" />
                                        {{
                                            account.is_active
                                                ? 'غیرفعال'
                                                : 'فعال'
                                        }}
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="px-6 py-14 text-center">
                <WalletCards class="mx-auto size-10 text-muted-foreground/35" />
                <p class="mt-3 text-sm font-bold">حساب خزانه‌ای پیدا نشد</p>
            </div>
        </section>
    </div>
</template>
