<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown, Menu } from '@lucide/vue';
import { ref } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import type { NavEntry, NavGroup, NavItem } from '@/types';

defineProps<{
    items: NavEntry[];
}>();

const { isCurrentOrParentUrl, isCurrentUrl } = useCurrentUrl();
const open = ref(false);

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
    <Sheet v-model:open="open">
        <SheetTrigger as-child>
            <button
                type="button"
                class="grid size-9 place-items-center rounded-lg border border-border/70 bg-card/60 lg:hidden"
                aria-label="بازکردن منوی اصلی"
            >
                <Menu class="size-4" />
            </button>
        </SheetTrigger>
        <SheetContent side="right" class="w-[19rem] max-w-[88vw] p-0">
            <SheetHeader class="border-b border-border/70 px-4 py-4 text-right">
                <SheetTitle class="flex items-center gap-2 text-sm">
                    <AppLogo />
                </SheetTitle>
                <SheetDescription class="text-xs">
                    دسترسی به بخش‌های حساب‌یار
                </SheetDescription>
            </SheetHeader>
            <nav
                class="flex-1 space-y-1 overflow-y-auto p-3"
                aria-label="منوی موبایل"
            >
                <template v-for="item in items" :key="item.title">
                    <Collapsible
                        v-if="'children' in item"
                        :default-open="isGroupActive(item)"
                        class="group/mobile-collapsible"
                    >
                        <CollapsibleTrigger as-child>
                            <button
                                type="button"
                                class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-right text-sm hover:bg-muted"
                            >
                                <component
                                    :is="item.icon"
                                    v-if="item.icon"
                                    class="size-4"
                                />
                                <span>{{ item.title }}</span>
                                <ChevronDown
                                    class="ms-auto size-3.5 transition-transform group-data-[state=open]/mobile-collapsible:rotate-180"
                                />
                            </button>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <div
                                class="mt-1 space-y-1 border-r border-border/70 pr-3"
                            >
                                <Link
                                    v-for="child in item.children"
                                    :key="child.title"
                                    :href="child.href"
                                    @click="open = false"
                                    class="block rounded-lg px-3 py-2 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
                                    :class="{
                                        'bg-primary/10 text-primary':
                                            isNavItemActive(
                                                child,
                                                item.children,
                                            ),
                                    }"
                                >
                                    {{ child.title }}
                                </Link>
                            </div>
                        </CollapsibleContent>
                    </Collapsible>

                    <Link
                        v-else
                        :href="item.href"
                        @click="open = false"
                        class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground"
                        :class="{
                            'bg-primary/10 text-primary': isNavItemActive(item),
                        }"
                    >
                        <component
                            :is="item.icon"
                            v-if="item.icon"
                            class="size-4"
                        />
                        <span>{{ item.title }}</span>
                    </Link>
                </template>
            </nav>
        </SheetContent>
    </Sheet>
</template>
