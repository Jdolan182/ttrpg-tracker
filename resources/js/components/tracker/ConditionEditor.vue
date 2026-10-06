<script setup lang="ts">
// A combatant's conditions: the active ones with how long each lasts, and a toggle for every condition.
import { useTrackerContext } from '@/composables/tracker/useTracker';
import { conditionDurations, conditionIcon, conditions, roundsLeft } from '@/lib/encounter';
import type { Combatant } from '@/types/tracker';

defineProps<{ combatant: Combatant }>();

const { toggleCondition, setConditionDuration } = useTrackerContext();

const durationFrom = (event: Event) => {
    const value = (event.target as HTMLSelectElement).value;
    return value === '' ? null : Number(value);
};
</script>

<template>
    <div class="space-y-2">
        <p class="text-sm text-muted-foreground">Conditions</p>
        <!-- Active ones, each with how long it lasts -->
        <ul v-if="combatant.conditions.length" class="flex flex-wrap gap-1.5">
            <li
                v-for="condition in combatant.conditions"
                :key="condition"
                class="inline-flex items-center gap-1 rounded-md border border-red-300 bg-red-50 py-0.5 pl-2 pr-1 text-xs text-red-800 dark:border-red-900 dark:bg-red-950/60 dark:text-red-200"
            >
                <component :is="conditionIcon(condition)" v-if="conditionIcon(condition)" class="size-3.5" aria-hidden="true" />
                <span class="font-medium">{{ condition }}</span>
                <select
                    :value="combatant.durations?.[condition] ?? ''"
                    class="h-6 rounded border-0 bg-transparent py-0 pl-1 pr-6 text-xs focus:ring-1 focus:ring-red-400"
                    :aria-label="`How long ${condition} lasts`"
                    @change="setConditionDuration(combatant, condition, durationFrom($event))"
                >
                    <option v-for="option in conditionDurations" :key="option.label" :value="option.value ?? ''">
                        {{ option.label }}
                    </option>
                    <!-- A count that's ticked down to something not in the list still shows correctly -->
                    <option
                        v-if="combatant.durations?.[condition] && !conditionDurations.some((o) => o.value === combatant.durations?.[condition])"
                        :value="combatant.durations[condition]"
                    >
                        {{ roundsLeft(combatant.durations[condition]) }}
                    </option>
                </select>
            </li>
        </ul>
        <div class="flex flex-wrap gap-1.5" role="group" aria-label="Conditions">
            <button
                v-for="condition in conditions"
                :key="condition.name"
                type="button"
                class="inline-flex size-9 items-center justify-center rounded-md border transition-colors"
                :class="
                    combatant.conditions.includes(condition.name)
                        ? 'border-red-500 bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
                        : 'border-border text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                "
                :title="condition.name"
                :aria-label="condition.name"
                :aria-pressed="combatant.conditions.includes(condition.name)"
                @click="toggleCondition(combatant, condition.name)"
            >
                <component :is="condition.icon" class="size-4" />
            </button>
        </div>
    </div>
</template>
