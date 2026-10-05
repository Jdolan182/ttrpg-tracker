<script setup lang="ts">
import StatBlock from '@/components/StatBlock.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { logEntry } from '@/lib/combatLog';
import { combatantsFor, insertByInitiative } from '@/lib/encounter';
import { readTracker, trackerStorageKey, writeTracker } from '@/lib/trackerStorage';
import type { SharedData } from '@/types';
import type { Creature, CreatureKind, CreatureSource } from '@/types/tracker';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Copy, Pencil, Plus, Search, Swords, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    creatures: Creature[];
    // Creature to show first, e.g. the one just created or edited.
    selectedId: number | null;
}>();

const page = usePage<SharedData>();
const user = page.props.auth.user;
const isGuest = !user;
// Updates after creating or deleting, since Inertia refreshes shared props on each visit.
const creatureLimit = computed(() => page.props.limits?.creatures);

const kindFilters: { value: CreatureKind | 'all'; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'monster', label: 'Monsters' },
    { value: 'npc', label: 'NPCs' },
    { value: 'player', label: 'Players' },
];

const sourceFilters: { value: CreatureSource | 'all'; label: string }[] = [
    { value: 'all', label: 'All sources' },
    { value: 'srd', label: 'SRD' },
    { value: 'homebrew', label: 'My creations' },
];

const kindLabels: Record<CreatureKind, string> = { monster: 'Monster', npc: 'NPC', player: 'Player' };

const search = ref('');
const kind = ref<CreatureKind | 'all'>('all');
const source = ref<CreatureSource | 'all'>('all');
const chosenId = ref<number | null>(props.selectedId);
const notice = ref('');

const results = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.creatures
        .filter((c) => kind.value === 'all' || c.kind === kind.value)
        .filter((c) => source.value === 'all' || c.source === source.value)
        .filter((c) => !term || c.name.toLowerCase().includes(term) || c.summary.toLowerCase().includes(term));
});

// Keep showing the chosen creature while it matches the filters, otherwise fall back to the first result.
const selected = computed(() => results.value.find((c) => c.id === chosenId.value) ?? results.value[0]);

const filterButtonClass = (active: boolean) =>
    active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground';

// Adds one of this creature to the encounter currently open in the tracker (kept in this browser).
const addToEncounter = (creature: Creature) => {
    const key = trackerStorageKey(user?.id ?? null);
    const tracker = readTracker(key) ?? {
        version: 2 as const,
        encounterId: null,
        name: 'Untitled encounter',
        combatants: [],
        round: 0,
        activeIndex: 0,
        log: [],
    };

    // Rolled initiative, slotted into the existing order without re-sorting anyone else.
    const activeId = tracker.combatants[tracker.activeIndex]?.id;
    const added = combatantsFor(creature, 1, null, tracker.combatants);
    insertByInitiative(tracker.combatants, added);
    tracker.activeIndex = Math.max(
        0,
        tracker.combatants.findIndex((c) => c.id === activeId),
    );
    // Arriving mid-fight goes in the history, as it would when added from the tracker.
    if (tracker.round > 0) {
        tracker.log.push(logEntry(tracker.round, { type: 'joined', targets: [added[0].name], amount: added[0].initiative }));
    }

    writeTracker(key, tracker);
    notice.value = `Added ${creature.name} to "${tracker.name}".`;
};

const deleteCreature = (creature: Creature) => {
    if (!window.confirm(`Delete ${creature.name}? Encounters that use it will keep its HP and AC, but lose its stat block.`)) return;

    router.delete(route('creatures.destroy', creature.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="Compendium" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-semibold tracking-tight">Compendium</h1>
                <span
                    v-if="creatureLimit"
                    class="ml-auto text-xs tabular-nums"
                    :class="creatureLimit.used >= creatureLimit.limit ? 'font-medium text-amber-700 dark:text-amber-400' : 'text-muted-foreground'"
                    title="Your own creatures, NPCs and players. SRD monsters don't count."
                >
                    {{ creatureLimit.used }} of {{ creatureLimit.limit }} creatures made
                </span>
                <Button
                    size="sm"
                    :class="creatureLimit ? '' : 'ml-auto'"
                    as-child
                    :title="isGuest ? 'Create a free account to make your own creatures' : undefined"
                >
                    <Link :href="isGuest ? route('register') : route('creatures.create')">
                        <Plus />
                        New creature
                    </Link>
                </Button>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <div class="relative w-full sm:w-72">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="search" type="search" placeholder="Search goblin, undead, ranger" class="pl-9" aria-label="Search creatures" />
                </div>

                <div class="flex rounded-md border border-border p-0.5" role="group" aria-label="Creature type">
                    <button
                        v-for="filter in kindFilters"
                        :key="filter.value"
                        type="button"
                        class="rounded px-3 py-1 text-sm transition-colors"
                        :class="filterButtonClass(kind === filter.value)"
                        :aria-pressed="kind === filter.value"
                        @click="kind = filter.value"
                    >
                        {{ filter.label }}
                    </button>
                </div>

                <div v-if="!isGuest" class="flex rounded-md border border-border p-0.5" role="group" aria-label="Source">
                    <button
                        v-for="filter in sourceFilters"
                        :key="filter.value"
                        type="button"
                        class="rounded px-3 py-1 text-sm transition-colors"
                        :class="filterButtonClass(source === filter.value)"
                        :aria-pressed="source === filter.value"
                        @click="source = filter.value"
                    >
                        {{ filter.label }}
                    </button>
                </div>
            </div>

            <p v-if="notice" class="text-sm text-emerald-700 dark:text-emerald-400" role="status">
                {{ notice }}
                <Link :href="route('encounters.index')" class="font-medium underline underline-offset-4">Go to the encounter</Link>
            </p>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
                <!-- Results -->
                <div class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                    <p class="border-b border-border px-4 py-2 text-xs text-muted-foreground">
                        {{ results.length }} {{ results.length === 1 ? 'creature' : 'creatures' }}
                    </p>
                    <button
                        v-for="creature in results"
                        :key="creature.id"
                        type="button"
                        class="flex w-full items-center gap-3 border-b border-l-4 border-b-border px-4 py-3 text-left text-sm transition-colors last:border-b-0 hover:bg-accent"
                        :class="selected?.id === creature.id ? 'border-l-primary bg-accent/60' : 'border-l-transparent'"
                        @click="chosenId = creature.id"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ creature.name }}</span>
                            <span class="block truncate text-xs text-muted-foreground">{{ creature.summary }}</span>
                        </span>
                        <span class="shrink-0 text-right text-xs text-muted-foreground">
                            <span class="block">{{ creature.rating }}</span>
                            <span class="block">{{ kindLabels[creature.kind] }} · {{ creature.source === 'srd' ? 'SRD' : 'Homebrew' }}</span>
                        </span>
                    </button>
                    <div v-if="results.length === 0" class="px-4 py-10 text-center text-sm text-muted-foreground">
                        <template v-if="source === 'homebrew' && !search && kind === 'all'">
                            You haven't made any creatures yet.
                            <Link :href="route('creatures.create')" class="font-medium text-foreground underline underline-offset-4">Create one</Link>
                        </template>
                        <template v-else>No creatures match those filters.</template>
                    </div>
                </div>

                <!-- Detail -->
                <div v-if="selected" class="space-y-4 rounded-xl border border-border bg-card p-4 shadow-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="mr-auto text-lg font-semibold">{{ selected.name }}</h2>
                        <Button variant="outline" size="sm" @click="addToEncounter(selected)">
                            <Swords />
                            Add to encounter
                        </Button>
                        <Button v-if="!isGuest" variant="outline" size="sm" as-child>
                            <Link :href="route('creatures.create', { from: selected.id })">
                                <Copy />
                                Duplicate
                            </Link>
                        </Button>
                        <template v-if="selected.source === 'homebrew'">
                            <Button variant="outline" size="sm" as-child>
                                <Link :href="route('creatures.edit', selected.id)">
                                    <Pencil />
                                    Edit
                                </Link>
                            </Button>
                            <Button variant="outline" size="sm" class="text-red-600 dark:text-red-400" @click="deleteCreature(selected)">
                                <Trash2 />
                                Delete
                            </Button>
                        </template>
                    </div>

                    <StatBlock :creature="selected" />
                </div>
            </div>

            <p class="text-xs text-muted-foreground">
                SRD content from the System Reference Document 5.1 by Wizards of the Coast LLC, licensed under
                <a href="https://creativecommons.org/licenses/by/4.0/" class="underline" target="_blank" rel="noopener noreferrer">CC-BY-4.0</a>.
            </p>
        </div>
    </AppLayout>
</template>
