<script setup lang="ts">
import { useStatDisplay } from '@/composables/useStatDisplay';
import { statParts } from '@/lib/stats';
import type { CreatureStat } from '@/types/tracker';

defineProps<{
    stats: CreatureStat[];
}>();

// Score, modifier or both, per the user's Display setting.
const statDisplay = useStatDisplay();
</script>

<template>
    <dl class="grid grid-cols-3 gap-2 text-center sm:grid-cols-6">
        <div v-for="stat in stats" :key="stat.label" class="rounded-md border border-border px-2 py-1.5">
            <dt class="text-xs text-muted-foreground">{{ stat.label }}</dt>
            <dd class="whitespace-nowrap">
                <span class="font-medium">{{ statParts(stat.value, statDisplay).main }}</span>
                <span v-if="statParts(stat.value, statDisplay).extra" class="ml-0.5 text-xs text-muted-foreground">
                    ({{ statParts(stat.value, statDisplay).extra }})
                </span>
            </dd>
        </div>
    </dl>
</template>
