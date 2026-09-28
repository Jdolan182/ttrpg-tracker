<script setup lang="ts">
import type { Creature } from '@/types/tracker';

defineProps<{
    creature: Creature;
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

        <dl v-if="creature.stats.length" class="grid grid-cols-3 gap-2 text-center sm:grid-cols-6">
            <div v-for="stat in creature.stats" :key="stat.label" class="rounded-md border border-border px-2 py-1.5">
                <dt class="text-xs text-muted-foreground">{{ stat.label }}</dt>
                <dd class="font-medium">{{ stat.value }}</dd>
            </div>
        </dl>

        <section v-if="creature.traits.length" class="space-y-2 border-t border-border pt-3">
            <p v-for="trait in creature.traits" :key="trait.name">
                <span class="font-medium italic">{{ trait.name }}.</span>
                {{ trait.description }}
            </p>
        </section>

        <section v-if="creature.actions.length" class="space-y-2 border-t border-border pt-3">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Actions</h3>
            <p v-for="action in creature.actions" :key="action.name">
                <span class="font-medium italic">{{ action.name }}.</span>
                {{ action.description }}
            </p>
        </section>
    </div>
</template>
