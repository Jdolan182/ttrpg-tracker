<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <!-- A d20. Drawn with strokes, so callers' fill-* classes don't fill it in. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linejoin="round"
        stroke-linecap="round"
        :class="className"
        v-bind="$attrs"
    >
        <path fill="none" d="M12 2 21 7v10l-9 5-9-5V7l9-5Z" />
        <path fill="none" d="M12 7.5 7 15.5h10L12 7.5Z" />
        <path fill="none" d="M12 2v5.5M3 7l4 8.5M21 7l-4 8.5M7 15.5 3 17M17 15.5 21 17M7 15.5 12 22l5-6.5" />
    </svg>
</template>
