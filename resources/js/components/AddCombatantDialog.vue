<script setup lang="ts">
import StatsEditor from '@/components/StatsEditor.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { defaultSide, defaultStatLabels, sides, switchableSides, type QuickCombatantDetails } from '@/lib/encounter';
import { statRows, statsFromRows } from '@/lib/stats';
import type { CombatantSide, Creature } from '@/types/tracker';
import { ChevronDown, Search } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps<{
    creatures: Creature[];
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    // A null initiative means roll it (monsters and NPCs) or 0 for players to fill in.
    add: [creature: Creature, count: number, initiative: number | null, side: CombatantSide];
    // Straight into the encounter, with no compendium entry.
    quickAdd: [details: QuickCombatantDetails];
}>();

// Kept between openings, so adding a whole party by hand doesn't mean switching tab each time.
const tab = ref<'compendium' | 'quick'>('compendium');
const error = ref('');

// From the compendium
const search = ref('');
const selectedId = ref<number | null>(null);
const count = ref<number | string>(1);
const initiative = ref<number | string>('');
const side = ref<CombatantSide>('enemy');

const results = computed(() => {
    const term = search.value.trim().toLowerCase();
    return props.creatures.filter((c) => !term || c.name.toLowerCase().includes(term) || c.summary.toLowerCase().includes(term));
});

const selected = computed(() => props.creatures.find((c) => c.id === selectedId.value));
const isPlayer = computed(() => selected.value?.kind === 'player');

// Quick add
const quickName = ref('');
const quickHp = ref<number | string>('');
const quickAc = ref<number | string>('');
const quickInitiative = ref<number | string>('');
const quickSide = ref<CombatantSide>('player');
const quickNameInput = ref<InstanceType<typeof Input> | null>(null);
// Optional stats, tucked away so a name and HP is all it takes. Blank values are left out.
const quickStats = ref(statRows(defaultStatLabels));
const showQuickStats = ref(false);

const resetQuick = () => {
    quickName.value = '';
    quickHp.value = '';
    quickAc.value = '';
    quickInitiative.value = '';
    // Keep the stat names (they're usually the same for the whole party), clear the values.
    quickStats.value = quickStats.value.map((row) => ({ ...row, value: '' }));
};

// Start clean each time the dialog opens.
watch(open, (isOpen) => {
    if (!isOpen) return;
    search.value = '';
    selectedId.value = null;
    count.value = 1;
    initiative.value = '';
    side.value = 'enemy';
    resetQuick();
    error.value = '';
});

// Picking a creature suggests its usual side; the DM can still change it (except for players).
watch(selected, (creature) => {
    if (creature) side.value = defaultSide(creature);
});

const wholeNumber = (value: number | string, min: number, max: number) => {
    const number = Number(value);
    return value !== '' && Number.isInteger(number) && number >= min && number <= max ? number : null;
};

const submitCompendium = () => {
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

const submitQuick = async ({ another = false } = {}) => {
    const name = quickName.value.trim();
    const hp = wholeNumber(quickHp.value, 1, 100000);
    const ac = quickAc.value === '' ? 10 : wholeNumber(quickAc.value, 0, 1000);
    const init = quickInitiative.value === '' ? 0 : wholeNumber(quickInitiative.value, -100, 1000);

    if (!name) {
        error.value = 'Enter a name.';
        return;
    }
    if (hp === null) {
        error.value = 'Enter their hit points (1 or more).';
        return;
    }
    if (ac === null || init === null) {
        error.value = 'AC and initiative must be whole numbers.';
        return;
    }

    emit('quickAdd', {
        name: name.slice(0, 100),
        hp,
        ac,
        initiative: init,
        side: quickSide.value,
        stats: showQuickStats.value ? statsFromRows(quickStats.value) : [],
    });
    error.value = '';

    if (!another) {
        open.value = false;
        return;
    }
    // Ready for the next one, e.g. the rest of the party.
    resetQuick();
    await nextTick();
    (quickNameInput.value?.$el as HTMLInputElement | undefined)?.focus();
};

const submit = () => (tab.value === 'quick' ? submitQuick() : submitCompendium());

const tabClass = (active: boolean) =>
    active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-xl">
            <DialogHeader>
                <DialogTitle>Add combatant</DialogTitle>
                <DialogDescription v-if="tab === 'compendium'">
                    Pick a creature from your compendium. Leave initiative blank to roll it; you can change it afterwards.
                </DialogDescription>
                <DialogDescription v-else>
                    Add someone straight to this encounter, like a player's character, without saving them to your compendium.
                </DialogDescription>
            </DialogHeader>

            <div class="flex rounded-md border border-border p-0.5" role="tablist" aria-label="How to add">
                <button
                    type="button"
                    role="tab"
                    :aria-selected="tab === 'compendium'"
                    class="flex-1 rounded px-3 py-1.5 text-sm transition-colors"
                    :class="tabClass(tab === 'compendium')"
                    @click="
                        tab = 'compendium';
                        error = '';
                    "
                >
                    From compendium
                </button>
                <button
                    type="button"
                    role="tab"
                    :aria-selected="tab === 'quick'"
                    class="flex-1 rounded px-3 py-1.5 text-sm transition-colors"
                    :class="tabClass(tab === 'quick')"
                    @click="
                        tab = 'quick';
                        error = '';
                    "
                >
                    Quick add
                </button>
            </div>

            <form class="space-y-4" @submit.prevent="submit">
                <template v-if="tab === 'compendium'">
                    <div class="relative">
                        <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            v-model="search"
                            type="search"
                            placeholder="Search goblin, undead, ranger"
                            class="pl-9"
                            aria-label="Search creatures"
                        />
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
                            @dblclick="submitCompendium"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{ creature.name }}</span>
                                <span class="block truncate text-xs text-muted-foreground">{{ creature.summary }}</span>
                            </span>
                            <span class="shrink-0 text-xs text-muted-foreground">{{ creature.rating }} · HP {{ creature.hp }}</span>
                        </button>
                        <p v-if="results.length === 0" class="px-3 py-6 text-center text-sm text-muted-foreground">
                            No creatures match that search. Try Quick add instead.
                        </p>
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
                </template>

                <template v-else>
                    <div class="grid gap-1.5">
                        <Label for="quick-name">Name</Label>
                        <Input id="quick-name" ref="quickNameInput" v-model="quickName" maxlength="100" placeholder="Aria" autocomplete="off" />
                    </div>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div class="grid gap-1.5">
                            <Label for="quick-hp">HP</Label>
                            <Input id="quick-hp" v-model="quickHp" type="number" min="1" placeholder="38" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="quick-ac">AC</Label>
                            <Input id="quick-ac" v-model="quickAc" type="number" min="0" placeholder="10" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="quick-initiative">Initiative</Label>
                            <Input id="quick-initiative" v-model="quickInitiative" type="number" placeholder="Their roll" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="quick-side">Side</Label>
                            <select id="quick-side" v-model="quickSide" class="h-10 rounded-md border border-input bg-background px-2 text-sm">
                                <option v-for="option in sides" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="rounded-md border border-border">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between px-3 py-2 text-sm"
                            :aria-expanded="showQuickStats"
                            @click="showQuickStats = !showQuickStats"
                        >
                            <span>
                                <span class="font-medium">Stats</span>
                                <span class="ml-1 text-muted-foreground">(optional)</span>
                            </span>
                            <ChevronDown class="size-4 text-muted-foreground transition-transform" :class="showQuickStats ? 'rotate-180' : ''" />
                        </button>
                        <div v-if="showQuickStats" class="border-t border-border px-3 py-3">
                            <StatsEditor v-model="quickStats" />
                            <p class="mt-1 text-xs text-muted-foreground">Leave any blank to skip it. DEX is used when rolling initiative.</p>
                        </div>
                    </div>
                </template>

                <p v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="open = false">Cancel</Button>
                    <template v-if="tab === 'quick'">
                        <Button type="button" variant="outline" @click="submitQuick({ another: true })">Add and add another</Button>
                        <Button type="submit">Add {{ quickName.trim() || 'combatant' }}</Button>
                    </template>
                    <Button v-else type="submit">{{ selected ? `Add ${selected.name}` : 'Add' }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
