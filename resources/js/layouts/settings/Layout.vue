<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Palette, ShieldCheck, UserRound } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'پروفایل',
        href: editProfile(),
        icon: UserRound,
    },
    {
        title: 'امنیت',
        href: editSecurity(),
        icon: ShieldCheck,
    },
    {
        title: 'ظاهر',
        href: editAppearance(),
        icon: Palette,
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="mx-auto w-full max-w-6xl px-4 py-7 sm:px-6 lg:py-10">
        <Heading
            title="تنظیمات حساب"
            description="اطلاعات، امنیت و ظاهر فضای کاری خود را مدیریت کنید."
        />

        <div class="mt-8 flex flex-col gap-7 lg:flex-row">
            <aside class="w-full max-w-xl lg:w-56">
                <nav
                    class="flex flex-col gap-1 rounded-2xl border border-border/70 bg-card p-2 shadow-sm"
                    aria-label="تنظیمات"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'h-10 w-full justify-start rounded-xl px-3',
                            {
                                'bg-primary/10 text-primary hover:bg-primary/12':
                                    isCurrentOrParentUrl(item.href),
                            },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="lg:hidden" />

            <div class="flex-1 md:max-w-2xl">
                <section class="max-w-2xl space-y-10">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
