<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    FilterX,
    PencilLine,
    Plus,
    Search,
    ShieldCheck,
    UserCog,
    UserPlus,
    UsersRound,
} from '@lucide/vue';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import {
    create as createUser,
    edit as editUser,
    index as usersIndex,
} from '@/routes/users';

type Role = 'manager' | 'accountant' | 'cashier' | 'warehouse' | 'viewer';
type UserRow = {
    id: number;
    name: string;
    email: string;
    role: Role;
    email_verified: boolean;
    created_at: string | null;
};

const props = defineProps<{
    users: {
        data: UserRow[];
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: { search: string; role: Role | null };
    summary: {
        total: number;
        managers: number;
        accountants: number;
        operators: number;
    };
}>();

const filters = reactive({
    search: props.filters.search,
    role: props.filters.role ?? '',
});
const count = new Intl.NumberFormat('fa-IR');
const date = new Intl.DateTimeFormat('fa-IR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
});
const roleMeta: Record<
    Role,
    { label: string; className: string; description: string }
> = {
    manager: {
        label: 'مدیر',
        className: 'bg-primary/10 text-primary',
        description: 'مدیریت کامل و کاربران',
    },
    accountant: {
        label: 'حسابدار',
        className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        description: 'عملیات و تأییدهای مالی',
    },
    cashier: {
        label: 'صندوق‌دار',
        className: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
        description: 'ساخت پیش‌نویس فروش',
    },
    warehouse: {
        label: 'انباردار',
        className: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
        description: 'دسترسی محدود انبار',
    },
    viewer: {
        label: 'مشاهده‌گر',
        className: 'bg-muted text-muted-foreground',
        description: 'دسترسی محدود',
    },
};
const pageLabel = (label: string) =>
    /^\d+$/.test(label) ? count.format(Number(label)) : '…';
const applyFilters = () => {
    router.get(
        usersIndex(),
        {
            search: filters.search.trim() || undefined,
            role: filters.role || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
const resetFilters = () => {
    filters.search = '';
    filters.role = '';
    router.get(usersIndex(), {}, { preserveScroll: true });
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'کاربران', href: usersIndex() }],
    },
});
</script>

<template>
    <Head title="مدیریت کاربران" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <UserCog class="size-5" />
                        <span class="text-xs font-bold">دسترسی‌های سیستم</span>
                    </div>
                    <h1 class="text-2xl font-black sm:text-3xl">
                        کاربران و نقش‌های حساب‌یار
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        نقش هر کاربر را دقیق تعیین کنید؛ هر تغییر در Audit Log
                        ثبت می‌شود.
                    </p>
                </div>
                <Button as-child class="h-11 rounded-xl px-5">
                    <Link :href="createUser()">
                        <UserPlus class="size-4" />
                        کاربر جدید
                    </Link>
                </Button>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-2xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">کل کاربران</p>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.total) }}
                </p>
            </article>
            <article class="rounded-2xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">مدیران</p>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.managers) }}
                </p>
            </article>
            <article class="rounded-2xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">حسابداران</p>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.accountants) }}
                </p>
            </article>
            <article class="rounded-2xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">کاربران عملیاتی</p>
                <p class="mt-3 text-2xl font-black">
                    {{ count.format(summary.operators) }}
                </p>
            </article>
        </section>

        <form
            class="grid gap-3 rounded-2xl border bg-card p-4 sm:grid-cols-[minmax(0,1fr)_220px_auto]"
            @submit.prevent="applyFilters"
        >
            <label class="relative">
                <Search
                    class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                />
                <input
                    v-model="filters.search"
                    type="search"
                    placeholder="نام یا ایمیل کاربر..."
                    class="h-10 w-full rounded-xl border bg-background pr-9 pl-3 text-sm"
                />
            </label>
            <select
                v-model="filters.role"
                class="h-10 rounded-xl border bg-background px-3 text-sm"
            >
                <option value="">همه نقش‌ها</option>
                <option value="manager">مدیر</option>
                <option value="accountant">حسابدار</option>
                <option value="cashier">صندوق‌دار</option>
                <option value="warehouse">انباردار</option>
                <option value="viewer">مشاهده‌گر</option>
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

        <section class="overflow-hidden rounded-2xl border bg-card">
            <div class="border-b px-5 py-4">
                <h2 class="text-sm font-bold">فهرست کاربران</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-right">
                    <thead class="bg-muted/35 text-[11px]">
                        <tr>
                            <th class="px-5 py-3">کاربر</th>
                            <th class="px-4 py-3">نقش</th>
                            <th class="px-4 py-3">ایمیل</th>
                            <th class="px-4 py-3">تاریخ ایجاد</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="user in users.data" :key="user.id">
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <span
                                        class="flex size-9 items-center justify-center rounded-xl bg-primary/10 text-primary"
                                    >
                                        <UsersRound class="size-4" />
                                    </span>
                                    <div>
                                        <p class="text-xs font-bold">
                                            {{ user.name }}
                                        </p>
                                        <p
                                            class="mt-1 flex items-center gap-1 text-[9px] text-muted-foreground"
                                        >
                                            <ShieldCheck class="size-3" />
                                            {{
                                                user.email_verified
                                                    ? 'ایمیل تأییدشده'
                                                    : 'ایمیل تأییدنشده'
                                            }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <span
                                    class="rounded-full px-2.5 py-1 text-[10px] font-semibold"
                                    :class="roleMeta[user.role].className"
                                >
                                    {{ roleMeta[user.role].label }}
                                </span>
                                <p
                                    class="mt-1 text-[9px] text-muted-foreground"
                                >
                                    {{ roleMeta[user.role].description }}
                                </p>
                            </td>
                            <td class="px-4 py-4 text-xs">
                                {{ user.email }}
                            </td>
                            <td class="px-4 py-4 text-[11px]">
                                {{
                                    user.created_at
                                        ? date.format(new Date(user.created_at))
                                        : '—'
                                }}
                            </td>
                            <td class="px-5 py-4 text-left">
                                <Button as-child variant="ghost" size="sm">
                                    <Link :href="editUser(user.id)">
                                        <PencilLine class="size-4" />
                                        ویرایش
                                    </Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-if="users.last_page > 1"
                class="flex items-center justify-between border-t px-5 py-4"
            >
                <span class="text-[10px] text-muted-foreground">
                    {{ users.from ?? 0 }} تا {{ users.to ?? 0 }} از
                    {{ users.total }}
                </span>
                <div class="flex gap-1">
                    <Button
                        variant="outline"
                        size="icon-sm"
                        :disabled="!users.links[0]?.url"
                        @click="
                            users.links[0]?.url &&
                            router.visit(users.links[0]!.url!)
                        "
                    >
                        <ChevronRight class="size-4" />
                    </Button>
                    <Button
                        v-for="(link, index) in users.links.slice(1, -1)"
                        :key="`${link.label}-${index}`"
                        :variant="link.active ? 'default' : 'ghost'"
                        size="icon-sm"
                        :disabled="!link.url"
                        @click="link.url && router.visit(link.url)"
                    >
                        {{ pageLabel(link.label) }}
                    </Button>
                    <Button
                        variant="outline"
                        size="icon-sm"
                        :disabled="!users.links.at(-1)?.url"
                        @click="
                            users.links.at(-1)?.url &&
                            router.visit(users.links.at(-1)!.url!)
                        "
                    >
                        <ChevronLeft class="size-4" />
                    </Button>
                </div>
            </div>
        </section>
    </div>
</template>
