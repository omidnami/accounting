<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Building2 } from '@lucide/vue';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { switchMethod as switchBranch } from '@/routes/branches';
import type { BreadcrumbItem } from '@/types';

type BranchContext = {
    active: { id: number; name: string; code: string };
    branches: Array<{ id: number; name: string; code: string }>;
    can_switch: boolean;
};

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage();
const branchContext = computed(
    () =>
        (page.props as { branch_context?: BranchContext | null })
            .branch_context ?? null,
);
const changeBranch = (event: Event) => {
    const branchId = Number((event.target as HTMLSelectElement).value);

    if (!branchId) {
        return;
    }

    router.patch(
        switchBranch().url,
        { branch_id: branchId },
        { preserveScroll: true },
    );
};
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
        <div v-if="branchContext" class="mr-auto">
            <label
                v-if="branchContext.can_switch"
                class="relative flex items-center"
            >
                <Building2
                    class="pointer-events-none absolute right-3 size-3.5 text-muted-foreground"
                />
                <select
                    :value="branchContext.active.id"
                    class="h-8 max-w-40 appearance-none rounded-full border border-border/70 bg-card/70 py-1 pr-8 pl-3 text-[10px] font-semibold"
                    aria-label="شعبهٔ فعال"
                    @change="changeBranch"
                >
                    <option
                        v-for="branch in branchContext.branches"
                        :key="branch.id"
                        :value="branch.id"
                    >
                        {{ branch.name }} — {{ branch.code }}
                    </option>
                </select>
            </label>
            <span
                v-else
                class="flex items-center gap-2 rounded-full border border-border/70 bg-card/70 px-3 py-1.5 text-[10px] text-muted-foreground"
            >
                <Building2 class="size-3" />
                {{ branchContext.active.name }}
            </span>
        </div>
        <span
            class="hidden items-center gap-2 rounded-full border border-border/70 bg-card/70 px-3 py-1.5 text-[10px] text-muted-foreground sm:flex"
        >
            <span class="size-1.5 rounded-full bg-primary" />
            آماده استفاده
        </span>
    </header>
</template>
