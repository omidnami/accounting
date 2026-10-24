<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import type { NavEntry, NavGroup, NavItem } from '@/types';

defineProps<{
    items: NavEntry[];
}>();

const { isCurrentOrParentUrl, isCurrentUrl } = useCurrentUrl();

function normalizedPath(href: NavItem['href']): string {
    return toUrl(href).replace(/\/+$/, '') || '/';
}

function isNavItemActive(item: NavItem, siblings: NavItem[] = []): boolean {
    if (isCurrentUrl(item.href)) {
        return true;
    }

    const itemPath = normalizedPath(item.href);

    if (itemPath === '/' || !isCurrentOrParentUrl(item.href)) {
        return false;
    }

    return !siblings.some((sibling) => {
        const siblingPath = normalizedPath(sibling.href);

        return (
            siblingPath.length > itemPath.length &&
            siblingPath.startsWith(`${itemPath}/`) &&
            (isCurrentUrl(sibling.href) || isCurrentOrParentUrl(sibling.href))
        );
    });
}

function isGroupActive(group: NavGroup): boolean {
    return group.children.some((item) => isNavItemActive(item, group.children));
}
</script>

<template>
    <nav
        class="hidden min-w-0 flex-1 [scrollbar-width:none] items-center gap-1 overflow-x-auto lg:flex [&::-webkit-scrollbar]:hidden"
        aria-label="منوی اصلی"
    >
        <template v-for="item in items" :key="item.title">
            <Link
                v-if="!('children' in item)"
                :href="item.href"
                class="flex h-9 shrink-0 items-center gap-2 rounded-lg px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                :class="{
                    'bg-primary/10 text-primary hover:bg-primary/12 hover:text-primary':
                        isNavItemActive(item),
                }"
            >
                <component :is="item.icon" v-if="item.icon" class="size-4" />
                <span>{{ item.title }}</span>
            </Link>

            <DropdownMenu v-else>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="flex h-9 shrink-0 items-center gap-2 rounded-lg px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        :class="{
                            'bg-primary/10 text-primary hover:bg-primary/12 hover:text-primary':
                                isGroupActive(item),
                        }"
                    >
                        <component
                            :is="item.icon"
                            v-if="item.icon"
                            class="size-4"
                        />
                        <span>{{ item.title }}</span>
                        <ChevronDown class="size-3" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="start"
                    :side-offset="7"
                    class="w-52 rounded-xl p-1.5"
                    dir="rtl"
                >
                    <DropdownMenuItem
                        v-for="child in item.children"
                        :key="child.title"
                        as-child
                    >
                        <Link
                            :href="child.href"
                            dir="rtl"
                            class="flex w-full items-center rounded-md px-2 py-2 text-right text-xs"
                            :class="{
                                'bg-primary/10 text-primary': isNavItemActive(
                                    child,
                                    item.children,
                                ),
                            }"
                        >
                            <span>{{ child.title }}</span>
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </template>
    </nav>
</template>
