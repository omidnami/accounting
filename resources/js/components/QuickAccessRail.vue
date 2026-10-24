<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useQuickActions } from '@/composables/useQuickActions';

const quickActions = useQuickActions();
</script>

<template>
    <aside
        class="sticky top-16 hidden h-[calc(100svh-4rem)] w-21 shrink-0 flex-col self-start overflow-y-auto border-e border-border/70 bg-card/35 py-3 lg:flex print:hidden"
        aria-label="دسترسی سریع"
    >
        <div class="mb-2 px-2 text-center">
            <span class="text-[9px] font-semibold text-muted-foreground">
                دسترسی سریع
            </span>
        </div>
        <nav class="flex flex-col items-center gap-1.5 px-2">
            <Link
                v-for="action in quickActions"
                :key="action.title"
                :href="action.href"
                :title="action.title"
                class="group flex w-full flex-col items-center gap-1 rounded-xl px-1 py-1.5 text-center transition-colors hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
            >
                <span
                    class="relative grid size-9 shrink-0 place-items-center rounded-xl"
                    :class="action.iconClass"
                >
                    <component :is="action.icon" class="size-4" />
                    <span
                        v-if="action.badgeIcon"
                        class="absolute -bottom-1 -left-1 grid size-3.5 place-items-center rounded-full border-2 border-background bg-background text-foreground"
                    >
                        <component :is="action.badgeIcon" class="size-2.5" />
                    </span>
                </span>
                <span
                    class="max-w-full truncate text-[9px] leading-4 font-medium text-muted-foreground transition-colors group-hover:text-foreground"
                >
                    {{ action.title }}
                </span>
            </Link>
        </nav>
    </aside>
</template>
