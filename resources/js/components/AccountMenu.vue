<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Activity,
    ChevronDown,
    LogOut,
    SlidersHorizontal,
    UserRoundPen,
} from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { logout } from '@/routes';
import { index as myActivity } from '@/routes/my-activity';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';

const page = usePage();
const user = computed(() => page.props.auth.user);

const accountActions = [
    {
        title: 'ویرایش پروفایل',
        icon: UserRoundPen,
        href: editProfile(),
    },
    {
        title: 'تنظیمات شخصی',
        icon: SlidersHorizontal,
        href: editAppearance(),
    },
    {
        title: 'آخرین فعالیت من',
        icon: Activity,
        href: myActivity(),
    },
];

const handleLogout = () => {
    router.flushAll();
};
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                aria-label="منوی حساب کاربری"
                dir="rtl"
                class="flex h-10 max-w-40 shrink-0 items-center gap-2 bg-transparent px-1 text-right focus-visible:outline-none sm:max-w-56 [&>div]:min-w-0"
            >
                <UserInfo :user="user" />
                <ChevronDown class="size-3.5 text-muted-foreground" />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            align="start"
            :side-offset="8"
            class="w-64 rounded-xl p-1.5"
            dir="rtl"
        >
            <DropdownMenuItem
                v-for="action in accountActions"
                :key="action.title"
                as-child
            >
                <Link
                    :href="action.href"
                    dir="rtl"
                    class="flex w-full cursor-pointer items-center gap-2.5 px-2 py-2 text-right"
                >
                    <component
                        :is="action.icon"
                        class="size-4 text-muted-foreground"
                    />
                    <span>{{ action.title }}</span>
                </Link>
            </DropdownMenuItem>

            <DropdownMenuSeparator />
            <DropdownMenuItem as-child>
                <Link
                    :href="logout.url()"
                    method="post"
                    as="button"
                    dir="rtl"
                    class="flex w-full cursor-pointer items-center gap-2.5 px-2 py-2 text-right text-destructive"
                    data-test="header-logout-button"
                    @click="handleLogout"
                >
                    <LogOut class="size-4" />
                    <span>خروج از سیستم</span>
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
