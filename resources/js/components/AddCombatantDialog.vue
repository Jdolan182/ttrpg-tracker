<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { defaultSide, switchableSides } from '@/lib/encounter';
import type { CombatantSide, Creature } from '@/types/tracker';
import { Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    creatures: Creature[];
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    // A null initiative means roll it (monsters and NPCs) or 0 for players to fill in.
    add: [creature: Creature, count: number, initiative: number | null, side: CombatantSide];
}>();

const search = ref('');
const selectedId = ref<number | null>(null);
const count = ref<number | string>(1);
const initiative = ref<number | string>('');
const side = ref<CombatantSide>('enemy');
const error = ref('');

const results = computed(() => {
    const term = search.value.trim().toLowerCase();
    return props.creatures.filter((c) => !term || c.name.toLowerCase().includes(term) || c.summary.toLowerCase().includes(term));
});

const selected = computed(() => props.creatures.find((c) => c.id === selectedId.value));
const isPlayer = computed(() => selected.value?.kind === 'player');

// Start clean each time the dialog opens.
watch(open, (isOpen) => {
    if (!isOpen) return;
    search.value = '';
    selectedId.value = null;
    count.value = 1;
    initiative.value = '';
    side.value = 'enemy';
    error.value = '';
});

// Picking a creature suggests its usual side; the DM can still change it (except for players).
watch(selected, (creature) => {
    if (creature) side.value = defaultSide(creature);
});

const submit = () => {
    const howMany = Number(count.value);
    const init = initiative.value === '' ? null : Number(initiative.value);

    if (!selected.value) {
        error.value = 'Choose a creature to add.';
        return;
    }
    if (!Number.isInteger(howMany) || howMany < 1 || howMany > 20) {
        error.value = 'Add between 1 and 20 at a time.';
        return;
    }
    if (init !== null && (!Number.isInteger(init) || init < -100 || init > 1000)) {
        error.value = 'Initiative must be a whole number.';
        return;
    }

    emit('add', selected.value, howMany, init, side.value);
    open.value = false;
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-lg">
            <DialogHeader>
                <DialogTitle>Add combatant</DialogTitle>
                <DialogDescription>
                    Pick a creature from your compendium. Leave initiative blank to roll it; you can change it afterwards.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="relative">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="search" type="search" placeholder="Search goblin, undead, ranger" class="pl-9" aria-label="Search creatures" />
                </div>

                <div class="max-h-64 overflow-y-auto rounded-md border border-border" role="listbox" aria-label="Creatures">
                    <button
                        v-for="creature in results"
                        :key="creature.id"
                        type="button"
                        role="option"
                        :aria-selected="creature.id === selectedId"
                        class="flex w-full items-center gap-3 border-b border-border px-3 py-2 text-left text-sm last:border-b-0 hover:bg-accent"
                        :class="creature.id === selectedId ? 'bg-accent' : ''"
                        @click="
                            selectedId = creature.id;
                            error = '';
                        "
                        @dblclick="submit"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ creature.name }}</span>
                            <span class="block truncate text-xs text-muted-foreground">{{ creature.summary }}</span>
                        </span>
                        <span class="shrink-0 text-xs text-muted-foreground">{{ creature.rating }} · HP {{ creature.hp }}</span>
                    </button>
                    <p v-if="results.length === 0" class="px-3 py-6 text-center text-sm text-muted-foreground">No creatures match that search.</p>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div class="grid gap-1.5">
                        <Label for="combatant-count">How many</Label>
                        <Input id="combatant-count" v-model="count" type="number" min="1" max="20" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="combatant-initiative">Initiative</Label>
                        <Input
                            id="combatant-initiative"
                            v-model="initiative"
                            type="number"
                            :placeholder="isPlayer ? 'Their roll' : 'Roll'"
                            :title="isPlayer ? 'Leave blank to enter it later' : 'Leave blank to roll d20 + DEX'"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="combatant-side">Side</Label>
                        <select
                            v-if="!isPlayer"
                            id="combatant-side"
                            v-model="side"
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option v-for="option in switchableSides" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                        <p v-else id="combatant-side" class="flex h-10 items-center text-sm text-muted-foreground">Player</p>
                    </div>
                </div>

                <p v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="open = false">Cancel</Button>
                    <Button type="submit">{{ selected ? `Add ${selected.name}` : 'Add' }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
