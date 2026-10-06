<script setup lang="ts">
import StatGrid from '@/components/StatGrid.vue';
import { initiativeBonus, initiativeFormula, limitLabel } from '@/lib/encounter';
import { formatModifier } from '@/lib/stats';
import type { Creature } from '@/types/tracker';
import { computed } from 'vue';

const props = defineProps<{
    creature: Creature;
    // The tracker shows actions itself, with Use buttons.
    hideActions?: boolean;
}>();

// What it adds to initiative, and whether that's its own bonus or worked out from DEX.
const initiative = computed(() => initiativeBonus(props.creature));
</script>

<template>
    <div class="space-y-4 text-sm">
        <div>
            <p class="text-muted-foreground">{{ creature.summary }}</p>
            <p class="text-muted-foreground">{{ creature.rating }}</p>
        </div>

        <dl class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <div class="rounded-md bg-muted px-3 py-2">
                <dt class="text-xs text-muted-foreground">AC</dt>
                <dd class="font-medium">{{ creature.ac }}</dd>
            </div>
            <div class="rounded-md bg-muted px-3 py-2">
                <dt class="text-xs text-muted-foreground">HP</dt>
                <dd class="font-medium">{{ creature.hp }}</dd>
            </div>
            <div class="rounded-md bg-muted px-3 py-2" :title="`Rolls ${initiativeFormula(creature)}`">
                <dt class="text-xs text-muted-foreground">Initiative</dt>
                <dd class="font-medium tabular-nums">
                    {{ formatModifier(initiative.bonus) }}
                    <span v-if="initiative.from === 'DEX'" class="text-xs font-normal text-muted-foreground">DEX</span>
                </dd>
            </div>
            <div class="rounded-md bg-muted px-3 py-2">
                <dt class="text-xs text-muted-foreground">Speed</dt>
                <dd class="font-medium">{{ creature.speed }}</dd>
            </div>
        </dl>

        <StatGrid v-if="creature.stats.length" :stats="creature.stats" />

        <section v-if="creature.traits.length" class="space-y-2 border-t border-border pt-3">
            <p v-for="trait in creature.traits" :key="trait.name">
                <span class="font-medium italic">{{ trait.name }}.</span>
                {{ trait.description }}
            </p>
        </section>

        <section v-if="creature.actions.length && !hideActions" class="space-y-2 border-t border-border pt-3">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Actions</h3>
            <p v-for="action in creature.actions" :key="action.name">
                <span class="font-medium italic"
                    >{{ action.name }}<template v-if="action.uses"> ({{ limitLabel(action) }})</template>.</span
                >
                {{ action.description }}
            </p>
        </section>
    </div>
</template>
