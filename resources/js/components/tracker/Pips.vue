<script setup lang="ts">
// Dots for something counted out: filled for what's left, hollow for what's used. Too many to read
// as dots (over 10) shows nothing, so callers show the numbers alongside.
defineProps<{
    total: number;
    left: number;
    // What's being counted, for screen readers.
    label: string;
}>();
</script>

<template>
    <span v-if="total <= 10" class="flex gap-0.5" role="img" :aria-label="`${label}: ${left} of ${total} left`">
        <span v-for="n in total" :key="n" class="size-2 rounded-full border border-primary" :class="n <= left ? 'bg-primary' : ''" />
    </span>
</template>
