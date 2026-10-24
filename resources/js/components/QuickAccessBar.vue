<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useQuickActions } from '@/composables/useQuickActions';

const quickActions = useQuickActions();
</script>

<template>
    <nav
        v-if="quickActions.length > 0"
        aria-label="دسترسی سریع"
        class="-mx-1 flex min-w-0 [scrollbar-width:none] items-center overflow-x-auto px-1 [&::-webkit-scrollbar]:hidden"
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
                        <component :is="action.badgeIcon" class="size-2.5" />
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
</template>
