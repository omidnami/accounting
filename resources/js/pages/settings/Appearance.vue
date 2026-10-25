<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Check, PanelRight, PanelTop } from '@lucide/vue';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { useMenuLayout } from '@/composables/useMenuLayout';
import { panelColors, usePanelColor } from '@/composables/usePanelColor';
import { edit } from '@/routes/appearance';

const { menuLayout, setMenuLayout } = useMenuLayout();
const { panelColor, setPanelColor } = usePanelColor();
const menuLayouts = [
    {
        value: 'sidebar',
        label: 'منوی کناری',
        description: 'منوی اصلی در کنار صفحه',
        icon: PanelRight,
    },
    {
        value: 'topbar',
        label: 'منوی بالا',
        description: 'منوی اصلی در هدر صفحه',
        icon: PanelTop,
    },
] as const;

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'تنظیمات ظاهر',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="تنظیمات ظاهر" />

    <h1 class="sr-only">تنظیمات ظاهر</h1>

    <div
        class="space-y-6 rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:p-6"
    >
        <Heading
            variant="small"
            title="ظاهر فضای کاری"
            description="حالت نمایش مناسب خود را انتخاب کنید."
        />
        <AppearanceTabs />
        <section class="space-y-3 border-t pt-6">
            <Heading
                variant="small"
                title="چیدمان منو"
                description="محل نمایش منوی اصلی را انتخاب کنید."
            />
            <div
                role="radiogroup"
                aria-label="چیدمان منو"
                class="grid grid-cols-1 gap-3 sm:grid-cols-2"
            >
                <button
                    v-for="layout in menuLayouts"
                    :key="layout.value"
                    type="button"
                    role="radio"
                    :aria-checked="menuLayout === layout.value"
                    :aria-label="layout.label"
                    :class="[
                        'flex items-center gap-3 rounded-xl border p-4 text-right transition-colors',
                        menuLayout === layout.value
                            ? 'border-primary bg-primary/5'
                            : 'border-border hover:bg-muted/50',
                    ]"
                    @click="setMenuLayout(layout.value)"
                >
                    <component
                        :is="layout.icon"
                        class="size-5 shrink-0 text-primary"
                    />
                    <span class="flex-1">
                        <span class="block text-sm font-semibold">{{
                            layout.label
                        }}</span>
                        <span
                            class="mt-1 block text-xs text-muted-foreground"
                            >{{ layout.description }}</span
                        >
                    </span>
                    <Check
                        v-if="menuLayout === layout.value"
                        class="size-4 shrink-0 text-primary"
                    />
                </button>
            </div>
        </section>
        <section class="space-y-3 border-t pt-6">
            <Heading
                variant="small"
                title="رنگ پنل"
                description="رنگ دکمه‌ها، لینک‌ها و بخش‌های فعال پنل را انتخاب کنید."
            />
            <div
                role="radiogroup"
                aria-label="رنگ پنل"
                class="grid grid-cols-2 gap-2 sm:grid-cols-5"
            >
                <button
                    v-for="color in panelColors"
                    :key="color.value"
                    type="button"
                    role="radio"
                    :aria-label="color.label"
                    :aria-checked="panelColor === color.value"
                    :data-panel-color-option="color.value"
                    :class="[
                        'flex flex-col items-center gap-2 rounded-xl border px-2 py-3 text-xs transition-colors',
                        panelColor === color.value
                            ? 'border-primary bg-primary/5'
                            : 'border-border hover:bg-muted/50',
                    ]"
                    @click="setPanelColor(color.value)"
                >
                    <span
                        :style="{ backgroundColor: color.swatch }"
                        class="flex size-8 items-center justify-center rounded-full text-white shadow-sm"
                    >
                        <Check
                            v-if="panelColor === color.value"
                            class="size-4"
                        />
                    </span>
                    <span>{{ color.label }}</span>
                </button>
            </div>
            <p class="text-xs leading-6 text-muted-foreground">
                تغییرات بلافاصله اعمال می‌شوند و در همین مرورگر باقی می‌مانند.
            </p>
        </section>
    </div>
</template>
