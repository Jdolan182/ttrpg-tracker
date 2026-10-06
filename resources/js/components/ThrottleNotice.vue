<script setup lang="ts">
// Shown when an action hit the rate limit (bootstrap/app.php turns that into a "throttle" error).
// It goes away on the next page visit, like any other form error.
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { Hourglass } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage<SharedData>();
const message = computed(() => page.props.errors?.throttle);
</script>

<template>
    <div v-if="message" class="border-b border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950/60 dark:text-red-100" role="alert">
        <div class="mx-auto flex items-center gap-3 px-4 py-2.5 text-sm md:max-w-7xl">
            <Hourglass class="size-4 shrink-0" />
            <p class="flex-1">{{ message }} Nothing was changed.</p>
        </div>
    </div>
</template>
