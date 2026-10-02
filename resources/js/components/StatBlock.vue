<script setup lang="ts">
import StatGrid from '@/components/StatGrid.vue';
import { limitLabel } from '@/lib/encounter';
import type { Creature } from '@/types/tracker';

defineProps<{
    creature: Creature;
    // The tracker shows actions itself, with Use buttons.
    hideActions?: boolean;
}>();
</script>

<template>
    <div class="space-y-4 text-sm">
        <div>
            <p class="text-muted-foreground">{{ creature.summary }}</p>
            <p class="text-muted-foreground">{{ creature.rating }}</p>
        </div>

        <dl class="grid grid-cols-3 gap-2">
            <div class="rounded-md bg-muted px-3 py-2">
                <dt class="text-xs text-muted-foreground">AC</dt>
                <dd class="font-medium">{{ creature.ac }}</dd>
            </div>
            <div class="rounded-md bg-muted px-3 py-2">
                <dt class="text-xs text-muted-foreground">HP</dt>
                <dd class="font-medium">{{ creature.hp }}</dd>
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
