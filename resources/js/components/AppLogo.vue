<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';

withDefaults(
    defineProps<{
        // The name next to the icon only shows from this width up; phones get just the icon.
        nameFrom?: 'always' | 'sm';
    }>(),
    { nameFrom: 'sm' },
);

// From APP_NAME, so renaming the app is one setting.
const appName = usePage<SharedData>().props.appName;
</script>

<template>
    <!-- One root element, so classes from the parent land on it -->
    <div class="flex items-center gap-x-2">
        <div
            class="flex aspect-square size-8 shrink-0 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground shadow-sm"
        >
            <AppLogoIcon class="size-5" />
        </div>
        <span class="truncate font-display text-base font-semibold leading-none" :class="nameFrom === 'sm' ? 'hidden sm:inline' : ''">{{
            appName
        }}</span>
    </div>
</template>
