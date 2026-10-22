<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    CircleOff,
    FilterX,
    Mail,
    PencilLine,
    Phone,
    Search,
    ShoppingBag,
    Truck,
    UserPlus,
    UsersRound,
} from '@lucide/vue';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import {
    create as createParty,
    edit as editParty,
    index as partiesIndex,
} from '@/routes/parties';

type PartyRow = {
    id: number;
    code: string;
    name: string;
    entity_type: 'individual' | 'organization';
    phone: string | null;
    email: string | null;
    is_active: boolean;
    roles: Array<'customer' | 'supplier'>;
    inactive_roles: Array<'customer' | 'supplier'>;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type Props = {
    parties: {
        data: PartyRow[];
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: PaginationLink[];
    };
    filters: {
        search: string;
        role: 'customer' | 'supplier' | null;
        status: 'active' | 'inactive' | null;
    };
    summary: {
        total: number;
        active: number;
        customers: number;
        suppliers: number;
    };
};

const props = defineProps<Props>();
const filters = reactive({
    search: props.filters.search,
    role: props.filters.role ?? '',
    status: props.filters.status ?? '',
});
const number = new Intl.NumberFormat('fa-IR');
const paginationLabel = (label: string) =>
    /^\d+$/.test(label) ? number.format(Number(label)) : '…';
const entityLabel = {
    individual: 'شخص حقیقی',
    organization: 'شخص حقوقی',
};

const applyFilters = () => {
    router.get(
        partiesIndex(),
        {
            search: filters.search.trim() || undefined,
            role: filters.role || undefined,
            status: filters.status || undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const resetFilters = () => {
    filters.search = '';
    filters.role = '';
    filters.status = '';
    router.get(
        partiesIndex(),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const goToPage = (url: string | null) => {
    if (url) {
        router.visit(url, {
            preserveState: true,
            preserveScroll: true,
        });
    }
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'طرف‌حساب‌ها',
                href: partiesIndex(),
            },
        ],
    },
});
</script>

<template>
    <Head title="طرف‌حساب‌ها" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="relative overflow-hidden rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="pointer-events-none absolute -top-24 left-8 size-64 rounded-full bg-primary/10 blur-3xl"
            />
            <div
                class="relative flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <UsersRound class="size-5" />
                        <span class="text-xs font-bold">دفتر طرف‌حساب‌ها</span>
                    </div>
                    <h1 class="text-2xl font-black tracking-tight sm:text-3xl">
                        مشتریان و تأمین‌کنندگان، در یک هویت واحد
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground"
                    >
                        هر شخص می‌تواند هم‌زمان مشتری و تأمین‌کننده باشد؛ گردش
                        مالی و مانده حساب او یکپارچه باقی می‌ماند.
                    </p>
                </div>
                <Button as-child class="h-11 rounded-xl px-5 shadow-md">
                    <Link :href="createParty()">
                        <UserPlus class="size-4" />
                        طرف‌حساب جدید
                    </Link>
                </Button>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article
                class="rounded-2xl border border-border/70 bg-card p-4 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <p class="text-xs text-muted-foreground">کل طرف‌حساب‌ها</p>
                    <UsersRound class="size-4 text-primary" />
                </div>
                <p class="mt-3 text-2xl font-black">
                    {{ number.format(summary.total) }}
                </p>
            </article>
            <article
                class="rounded-2xl border border-border/70 bg-card p-4 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <p class="text-xs text-muted-foreground">هویت‌های فعال</p>
                    <CircleCheck class="size-4 text-emerald-600" />
                </div>
                <p class="mt-3 text-2xl font-black">
                    {{ number.format(summary.active) }}
                </p>
            </article>
            <article
                class="rounded-2xl border border-border/70 bg-card p-4 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <p class="text-xs text-muted-foreground">مشتریان فعال</p>
                    <ShoppingBag class="size-4 text-sky-600" />
                </div>
                <p class="mt-3 text-2xl font-black">
                    {{ number.format(summary.customers) }}
                </p>
            </article>
            <article
                class="rounded-2xl border border-border/70 bg-card p-4 shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <p class="text-xs text-muted-foreground">
                        تأمین‌کنندگان فعال
                    </p>
                    <Truck class="size-4 text-amber-600" />
                </div>
                <p class="mt-3 text-2xl font-black">
                    {{ number.format(summary.suppliers) }}
                </p>
            </article>
        </section>

        <form
            class="grid gap-3 rounded-2xl border border-border/70 bg-card p-4 shadow-sm md:grid-cols-[minmax(0,1.7fr)_1fr_1fr_auto]"
            @submit.prevent="applyFilters"
        >
            <label class="relative block">
                <span class="sr-only">جست‌وجوی طرف‌حساب</span>
                <Search
                    class="absolute inset-y-0 right-3 my-auto size-4 text-muted-foreground"
                />
                <input
                    v-model="filters.search"
                    type="search"
                    placeholder="جست‌وجو با کد، نام، تلفن یا کد ملی..."
                    class="h-10 w-full rounded-xl border border-input bg-background py-2 pr-9 pl-3 text-sm transition outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                />
            </label>
            <label>
                <span class="sr-only">نقش طرف‌حساب</span>
                <select
                    v-model="filters.role"
                    class="h-10 w-full rounded-xl border border-input bg-background px-3 text-sm transition outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                >
                    <option value="">همه نقش‌ها</option>
                    <option value="customer">مشتری</option>
                    <option value="supplier">تأمین‌کننده</option>
                </select>
            </label>
            <label>
                <span class="sr-only">وضعیت طرف‌حساب</span>
                <select
                    v-model="filters.status"
                    class="h-10 w-full rounded-xl border border-input bg-background px-3 text-sm transition outline-none focus:border-ring focus:ring-3 focus:ring-ring/20"
                >
                    <option value="">همه وضعیت‌ها</option>
                    <option value="active">فعال</option>
                    <option value="inactive">غیرفعال</option>
                </select>
            </label>
            <div class="flex gap-2">
                <Button type="submit" class="h-10 flex-1 rounded-xl px-5">
                    <Search class="size-4" />
                    فیلتر
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    class="size-10 rounded-xl"
                    title="پاک‌کردن فیلترها"
                    @click="resetFilters"
                >
                    <FilterX class="size-4" />
                </Button>
            </div>
        </form>

        <section
            class="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
        >
            <div
                class="flex flex-col gap-2 border-b border-border/70 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h2 class="text-sm font-bold">فهرست طرف‌حساب‌ها</h2>
                    <p class="mt-1 text-[11px] text-muted-foreground">
                        {{ number.format(parties.total) }} نتیجه در این فروشگاه
                    </p>
                </div>
                <p class="text-[11px] text-muted-foreground">
                    حذف انجام نمی‌شود؛ برای قطع همکاری، هویت را غیرفعال کنید.
                </p>
            </div>

            <div v-if="parties.data.length" class="overflow-x-auto">
                <table class="w-full min-w-[850px] text-right">
                    <thead
                        class="bg-muted/35 text-[11px] text-muted-foreground"
                    >
                        <tr>
                            <th class="px-5 py-3 font-semibold">طرف‌حساب</th>
                            <th class="px-4 py-3 font-semibold">ارتباط</th>
                            <th class="px-4 py-3 font-semibold">نقش‌ها</th>
                            <th class="px-4 py-3 font-semibold">وضعیت</th>
                            <th class="px-5 py-3 text-left font-semibold">
                                عملیات
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        <tr
                            v-for="party in parties.data"
                            :key="party.id"
                            class="transition-colors hover:bg-muted/20"
                        >
                            <td class="px-5 py-4">
                                <div class="flex items-start gap-3">
                                    <span
                                        class="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                                    >
                                        <UsersRound class="size-5" />
                                    </span>
                                    <div>
                                        <p class="text-sm font-bold">
                                            {{ party.name }}
                                        </p>
                                        <div
                                            class="mt-1 flex flex-wrap items-center gap-2 text-[10px] text-muted-foreground"
                                        >
                                            <span
                                                dir="ltr"
                                                class="rounded-md bg-muted px-1.5 py-0.5 font-mono"
                                                >{{ party.code }}</span
                                            >
                                            <span>{{
                                                entityLabel[party.entity_type]
                                            }}</span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <div
                                    class="space-y-1.5 text-[11px] text-muted-foreground"
                                >
                                    <p
                                        v-if="party.phone"
                                        class="flex items-center gap-1.5"
                                    >
                                        <Phone class="size-3.5" />
                                        <span dir="ltr">{{ party.phone }}</span>
                                    </p>
                                    <p
                                        v-if="party.email"
                                        class="flex items-center gap-1.5"
                                        dir="ltr"
                                    >
                                        <Mail class="size-3.5" />
                                        {{ party.email }}
                                    </p>
                                    <span v-if="!party.phone && !party.email"
                                        >ثبت نشده</span
                                    >
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <div class="flex flex-wrap gap-1.5">
                                    <span
                                        v-if="party.roles.includes('customer')"
                                        class="inline-flex items-center gap-1 rounded-full bg-sky-500/10 px-2.5 py-1 text-[10px] font-semibold text-sky-700 dark:text-sky-300"
                                    >
                                        <ShoppingBag class="size-3" />
                                        مشتری
                                    </span>
                                    <span
                                        v-if="party.roles.includes('supplier')"
                                        class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2.5 py-1 text-[10px] font-semibold text-amber-700 dark:text-amber-300"
                                    >
                                        <Truck class="size-3" />
                                        تأمین‌کننده
                                    </span>
                                    <span
                                        v-for="role in party.inactive_roles"
                                        :key="role"
                                        class="rounded-full border border-dashed px-2 py-1 text-[9px] text-muted-foreground"
                                    >
                                        نقش
                                        {{
                                            role === 'customer'
                                                ? 'مشتری'
                                                : 'تأمین‌کننده'
                                        }}
                                        غیرفعال
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-semibold"
                                    :class="
                                        party.is_active
                                            ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                >
                                    <component
                                        :is="
                                            party.is_active
                                                ? CircleCheck
                                                : CircleOff
                                        "
                                        class="size-3"
                                    />
                                    {{ party.is_active ? 'فعال' : 'غیرفعال' }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-left">
                                <Button
                                    as-child
                                    variant="ghost"
                                    size="sm"
                                    class="rounded-lg"
                                >
                                    <Link :href="editParty(party.id)">
                                        <PencilLine class="size-4" />
                                        ویرایش
                                    </Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="px-6 py-16 text-center">
                <span
                    class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground"
                >
                    <UsersRound class="size-6" />
                </span>
                <h3 class="mt-4 text-sm font-bold">طرف‌حسابی پیدا نشد</h3>
                <p class="mt-1 text-xs text-muted-foreground">
                    فیلترها را تغییر دهید یا اولین طرف‌حساب را ثبت کنید.
                </p>
                <Button as-child variant="outline" class="mt-5 rounded-xl">
                    <Link :href="createParty()">ثبت طرف‌حساب</Link>
                </Button>
            </div>

            <div
                v-if="parties.last_page > 1"
                class="flex flex-col gap-3 border-t border-border/70 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="text-[11px] text-muted-foreground">
                    نمایش {{ number.format(parties.from ?? 0) }} تا
                    {{ number.format(parties.to ?? 0) }} از
                    {{ number.format(parties.total) }}
                </p>
                <div class="flex items-center gap-1">
                    <Button
                        variant="outline"
                        size="icon-sm"
                        :disabled="!parties.links[0]?.url"
                        aria-label="صفحه قبل"
                        @click="goToPage(parties.links[0]?.url ?? null)"
                    >
                        <ChevronRight class="size-4" />
                    </Button>
                    <Button
                        v-for="(link, index) in parties.links.slice(1, -1)"
                        :key="`${link.label}-${index}`"
                        :variant="link.active ? 'default' : 'ghost'"
                        size="icon-sm"
                        class="text-xs"
                        :disabled="!link.url"
                        @click="goToPage(link.url)"
                    >
                        {{ paginationLabel(link.label) }}
                    </Button>
                    <Button
                        variant="outline"
                        size="icon-sm"
                        :disabled="!parties.links.at(-1)?.url"
                        aria-label="صفحه بعد"
                        @click="goToPage(parties.links.at(-1)?.url ?? null)"
                    >
                        <ChevronLeft class="size-4" />
                    </Button>
                </div>
            </div>
        </section>
    </div>
</template>
