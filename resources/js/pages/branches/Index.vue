<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Building2,
    CheckCircle2,
    MapPin,
    Package,
    PencilLine,
    Plus,
    Power,
    Star,
    UsersRound,
} from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    create as createBranch,
    defaultMethod as setDefaultBranch,
    edit as editBranch,
    index as branchesIndex,
    status as updateBranchStatus,
} from '@/routes/branches';

type BranchRow = {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    address: string | null;
    is_default: boolean;
    is_active: boolean;
    users_count: number;
    warehouses_count: number;
    is_current: boolean;
};

defineProps<{ branches: BranchRow[] }>();

const setDefault = (branch: BranchRow) => {
    router.patch(setDefaultBranch(branch.id).url, {}, { preserveScroll: true });
};
const toggleStatus = (branch: BranchRow) => {
    if (
        branch.is_active &&
        !window.confirm(`شعبه «${branch.name}» غیرفعال شود؟`)
    ) {
        return;
    }

    router.patch(
        updateBranchStatus(branch.id).url,
        { is_active: !branch.is_active },
        { preserveScroll: true },
    );
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'شعبه‌ها', href: branchesIndex() }],
    },
});
</script>

<template>
    <Head title="مدیریت شعبه‌ها" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <section
            class="rounded-3xl border border-primary/10 bg-[linear-gradient(135deg,hsl(var(--card))_0%,hsl(var(--primary)/.09)_100%)] p-6 sm:p-8"
        >
            <div
                class="flex flex-col justify-between gap-5 md:flex-row md:items-end"
            >
                <div>
                    <div class="mb-3 flex items-center gap-2 text-primary">
                        <Building2 class="size-5" />
                        <span class="text-xs font-bold">ساختار فروشگاه</span>
                    </div>
                    <h1 class="text-2xl font-black sm:text-3xl">
                        شعبه‌ها و انبارهای پیش‌فرض
                    </h1>
                    <p class="mt-2 text-sm leading-7 text-muted-foreground">
                        هر شعبه با یک انبار پیش‌فرض مستقل ساخته می‌شود. کارمندان
                        می‌توانند به شعبهٔ خود محدود شوند.
                    </p>
                </div>
                <Button as-child class="h-11 rounded-xl px-5">
                    <Link :href="createBranch()">
                        <Plus class="size-4" />
                        شعبه جدید
                    </Link>
                </Button>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article
                v-for="branch in branches"
                :key="branch.id"
                class="rounded-2xl border bg-card p-5 shadow-sm"
                :class="branch.is_active ? '' : 'opacity-70'"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-black">
                                {{ branch.name }}
                            </h2>
                            <span
                                v-if="branch.is_default"
                                class="rounded-full bg-primary/10 px-2 py-1 text-[9px] font-bold text-primary"
                            >
                                پیش‌فرض
                            </span>
                            <span
                                v-if="branch.is_current"
                                class="rounded-full bg-emerald-500/10 px-2 py-1 text-[9px] font-bold text-emerald-700"
                            >
                                فعال
                            </span>
                            <span
                                v-if="!branch.is_active"
                                class="rounded-full bg-red-500/10 px-2 py-1 text-[9px] font-bold text-red-700"
                            >
                                غیرفعال
                            </span>
                        </div>
                        <p class="mt-1 font-mono text-xs text-muted-foreground">
                            {{ branch.code }}
                        </p>
                    </div>
                    <Building2 class="size-5 text-primary" />
                </div>

                <div class="mt-5 grid grid-cols-2 gap-3 text-xs">
                    <p class="flex items-center gap-2 text-muted-foreground">
                        <UsersRound class="size-4" />
                        {{ branch.users_count }} کارمند
                    </p>
                    <p class="flex items-center gap-2 text-muted-foreground">
                        <Package class="size-4" />
                        {{ branch.warehouses_count }} انبار
                    </p>
                </div>

                <p
                    v-if="branch.address"
                    class="mt-3 flex items-start gap-2 text-[10px] leading-5 text-muted-foreground"
                >
                    <MapPin class="mt-0.5 size-3.5 shrink-0" />
                    {{ branch.address }}
                </p>

                <div class="mt-5 flex flex-wrap gap-2">
                    <Button as-child variant="outline" size="sm">
                        <Link :href="editBranch(branch.id)">
                            <PencilLine class="size-3.5" /> ویرایش
                        </Link>
                    </Button>
                    <Button
                        v-if="!branch.is_default && branch.is_active"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="setDefault(branch)"
                    >
                        <Star class="size-3.5" /> پیش‌فرض
                    </Button>
                    <Button
                        type="button"
                        :variant="branch.is_active ? 'ghost' : 'outline'"
                        size="sm"
                        :class="branch.is_active ? 'text-red-600' : ''"
                        @click="toggleStatus(branch)"
                    >
                        <Power class="size-3.5" />
                        {{ branch.is_active ? 'غیرفعال' : 'فعال‌سازی' }}
                    </Button>
                </div>
            </article>

            <article
                v-if="branches.length === 0"
                class="rounded-2xl border border-dashed bg-card p-10 text-center md:col-span-2 xl:col-span-3"
            >
                <CheckCircle2 class="mx-auto size-9 text-muted-foreground/40" />
                <p class="mt-3 text-sm font-bold">هنوز شعبه‌ای ثبت نشده است</p>
            </article>
        </section>
    </div>
</template>
