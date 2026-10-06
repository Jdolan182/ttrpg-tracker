<script setup lang="ts">
// What the last import added, or why it couldn't. Goes under the header of pages with a BackupMenu.
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage<SharedData>();
const error = computed(() => page.props.errors.backup ?? page.props.errors.limit ?? page.props.errors.file);
const status = computed(() => page.props.flash.imported);
</script>

<template>
    <p
        v-if="error"
        class="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200"
        role="alert"
    >
        <CircleAlert class="mt-0.5 size-4 shrink-0" />
        {{ error }}
    </p>
    <p
        v-else-if="status"
        class="flex items-start gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-100"
        role="status"
    >
        <CircleCheck class="mt-0.5 size-4 shrink-0" />
        {{ status }}
    </p>
</template>
