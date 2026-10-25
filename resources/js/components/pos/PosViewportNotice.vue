<script setup lang="ts">
import { ArrowRight, Expand, Monitor } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import {
    DialogContent,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
} from 'reka-ui';
import { DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { dashboard } from '@/routes';

defineProps<{ open: boolean }>();
defineEmits<{ fullscreen: [] }>();
</script>

<template>
    <DialogRoot :open="open">
        <DialogPortal>
            <DialogOverlay class="pos-viewport-backdrop" />
            <DialogContent
                class="pos-viewport-notice"
                dir="rtl"
                @escape-key-down.prevent
                @interact-outside.prevent
            >
                <div class="pos-viewport-art" aria-hidden="true">
                    <span class="pos-viewport-orbit" />
                    <div class="pos-viewport-monitor">
                        <Monitor :size="44" :stroke-width="1.5" />
                    </div>
                    <span class="pos-viewport-size">۱۰۰۰ px</span>
                </div>
                <span class="pos-viewport-eyebrow"
                    >فضای مناسب برای فروش راحت‌تر</span
                >
                <DialogTitle class="pos-viewport-title"
                    >صندوق به صفحهٔ بزرگ‌تری نیاز دارد</DialogTitle
                >
                <DialogDescription class="pos-viewport-description">
                    برای استفاده از صندوق، عرض پنجره باید حداقل ۱۰۰۰ پیکسل باشد.
                    پنجره را بزرگ‌تر یا تمام‌صفحه کنید؛ در موبایل از نمایشگر
                    بزرگ‌تری استفاده کنید.
                </DialogDescription>
                <p class="pos-viewport-hint">
                    با رسیدن به اندازهٔ مناسب، صندوق خودکار در دسترس قرار
                    می‌گیرد.
                </p>
                <div class="pos-viewport-actions">
                    <button
                        type="button"
                        class="pos-primary"
                        @click="$emit('fullscreen')"
                    >
                        <Expand :size="18" />نمایش تمام‌صفحه
                    </button>
                    <Link :href="dashboard()" class="pos-secondary"
                        ><ArrowRight :size="18" />بازگشت به داشبورد</Link
                    >
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
