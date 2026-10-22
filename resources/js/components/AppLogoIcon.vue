<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 40 42"
        :class="className"
        v-bind="$attrs"
    >
        <rect
            x="2"
            y="3.5"
            width="36"
            height="35"
            rx="10"
            fill="currentColor"
            opacity="0.16"
        />
        <path
            d="M10.5 13.5h13M10.5 20h10M10.5 26.5h6.5"
            fill="none"
            stroke="currentColor"
            stroke-width="2.4"
            stroke-linecap="round"
        />
        <path
            d="m23.5 27.2 3.4 3.4 6.4-8"
            fill="none"
            stroke="currentColor"
            stroke-width="2.8"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
    </svg>
</template>
