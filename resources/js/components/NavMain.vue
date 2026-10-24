<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
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
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>فضای کاری</SidebarGroupLabel>
        <SidebarMenu>
            <template v-for="item in items" :key="item.title">
                <Collapsible
                    v-if="'children' in item"
                    :default-open="isGroupActive(item)"
                    class="group/collapsible"
                >
                    <SidebarMenuItem>
                        <CollapsibleTrigger as-child>
                            <SidebarMenuButton
                                type="button"
                                :is-active="isGroupActive(item)"
                                :tooltip="item.title"
                            >
                                <component :is="item.icon" v-if="item.icon" />
                                <span class="truncate">{{ item.title }}</span>
                                <ChevronDown
                                    class="ms-auto size-4 shrink-0 transition-transform duration-200 group-data-[state=open]/collapsible:rotate-180"
                                />
                            </SidebarMenuButton>
                        </CollapsibleTrigger>

                        <CollapsibleContent>
                            <SidebarMenuSub class="border-r border-l-0">
                                <SidebarMenuSubItem
                                    v-for="child in item.children"
                                    :key="child.title"
                                >
                                    <SidebarMenuSubButton
                                        as-child
                                        :is-active="
                                            isNavItemActive(
                                                child,
                                                item.children,
                                            )
                                        "
                                    >
                                        <Link :href="child.href">
                                            <component
                                                :is="child.icon"
                                                v-if="child.icon"
                                            />
                                            <span>{{ child.title }}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </SidebarMenuItem>
                </Collapsible>

                <SidebarMenuItem v-else>
                    <SidebarMenuButton
                        as-child
                        :is-active="isNavItemActive(item)"
                        :tooltip="item.title"
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" v-if="item.icon" />
                            <span>{{ item.title }}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </template>
        </SidebarMenu>
    </SidebarGroup>
</template>
