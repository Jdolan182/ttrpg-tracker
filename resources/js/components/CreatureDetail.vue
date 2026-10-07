<script setup lang="ts">
// One creature in the compendium: what you can do with it, and its stat block. Beside the list on wide
// screens; in a panel that slides up over the list on phones, so the list keeps its place.
import StatBlock from '@/components/StatBlock.vue';
import { Button } from '@/components/ui/button';
import type { Creature } from '@/types/tracker';
import { Link } from '@inertiajs/vue3';
import { Copy, Pencil, Swords, Trash2 } from 'lucide-vue-next';

defineProps<{
    creature: Creature;
    isGuest: boolean;
    // "Added to …", shown here when the page's own notice is out of sight (behind the phone panel).
    notice?: string;
    // In the phone panel: leave room for its close button.
    inPanel?: boolean;
}>();

const emit = defineEmits<{ add: [creature: Creature]; delete: [creature: Creature] }>();
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2" :class="inPanel ? 'pr-8' : ''">
            <h2 class="mr-auto text-lg font-semibold">{{ creature.name }}</h2>
            <Button variant="outline" size="sm" @click="emit('add', creature)">
                <Swords />
                Add to encounter
            </Button>
            <Button v-if="!isGuest" variant="outline" size="sm" as-child>
                <Link :href="route('creatures.create', { from: creature.id })">
                    <Copy />
                    Duplicate
                </Link>
            </Button>
            <template v-if="creature.source === 'homebrew'">
                <Button variant="outline" size="sm" as-child>
                    <Link :href="route('creatures.edit', creature.id)">
                        <Pencil />
                        Edit
                    </Link>
                </Button>
                <Button variant="outline" size="sm" class="text-red-600 dark:text-red-400" @click="emit('delete', creature)">
                    <Trash2 />
                    Delete
                </Button>
            </template>
        </div>

        <p v-if="notice" class="text-sm text-emerald-700 dark:text-emerald-400" role="status">
            {{ notice }}
            <Link :href="route('encounters.index')" class="font-medium underline underline-offset-4">Go to the encounter</Link>
        </p>

        <StatBlock :creature="creature" />
    </div>
</template>
