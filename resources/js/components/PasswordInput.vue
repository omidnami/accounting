<script setup lang="ts">
import { Eye, EyeOff } from '@lucide/vue';
import { ref, useTemplateRef } from 'vue';
import type { HTMLAttributes } from 'vue';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    class?: HTMLAttributes['class'];
    autofocus?: boolean;
}>();

const showPassword = ref(false);
const inputRef = useTemplateRef('inputRef');

defineExpose({
    $el: inputRef,
    focus: () => inputRef.value?.$el?.focus(),
});
</script>

<template>
    <div class="relative">
        <Input
            v-focus="props.autofocus"
            ref="inputRef"
            :type="showPassword ? 'text' : 'password'"
            :class="cn('pl-10', props.class)"
            dir="ltr"
            v-bind="$attrs"
        />
        <button
            type="button"
            @click="showPassword = !showPassword"
            :class="
                cn(
                    'absolute inset-y-0 left-0 flex items-center rounded-l-md px-3 text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none',
                )
            "
            :aria-label="
                showPassword ? 'پنهان‌کردن رمز عبور' : 'نمایش رمز عبور'
            "
            :tabindex="-1"
        >
            <EyeOff v-if="showPassword" class="size-4" />
            <Eye v-else class="size-4" />
        </button>
    </div>
</template>
