<script setup lang="ts">
import AddCombatantDialog from '@/components/AddCombatantDialog.vue';
import StatBlock from '@/components/StatBlock.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { byInitiative, combatantsFor, conditions } from '@/lib/encounter';
import { readTracker, trackerStorageKey, writeTracker } from '@/lib/trackerStorage';
import { plainCopy } from '@/lib/utils';
import type { SharedData } from '@/types';
import type { Combatant, Creature, Encounter } from '@/types/tracker';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Download, FilePlus2, MonitorPlay, Plus, RotateCcw, Save, Trash2, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    // The signed-in user's encounters, most recently updated first. Always empty for guests.
    savedEncounters: Encounter[];
    // Everything the user can add: SRD creatures, plus their own when signed in.
    creatures: Creature[];
}>();

const page = usePage<SharedData>();
const user = page.props.auth.user;
const isGuest = !user;

const encounterId = ref<number | null>(null);
const name = ref('');
const combatants = ref<Combatant[]>([]);
const round = ref(1);
const activeIndex = ref(0);
const selectedId = ref<string | null>(null);
const amount = ref('');
const conditionToAdd = ref('');
const addOpen = ref(false);
const saving = ref(false);
const saveError = ref('');

const creaturesById = computed(() => new Map(props.creatures.map((c) => [c.id, c])));
const active = computed<Combatant | undefined>(() => combatants.value[activeIndex.value]);
const selected = computed(() => combatants.value.find((c) => c.id === selectedId.value) ?? active.value);
const selectedCreature = computed(() => (selected.value ? creaturesById.value.get(selected.value.creatureId) : undefined));

// Unsaved-changes tracking: compare the current fight with how it was when last saved or opened.
const snapshotOf = (state: Pick<Encounter, 'name' | 'round' | 'activeIndex' | 'combatants'>) =>
    JSON.stringify({ name: state.name, round: state.round, activeIndex: state.activeIndex, combatants: state.combatants });
const currentSnapshot = () => snapshotOf({ name: name.value, round: round.value, activeIndex: activeIndex.value, combatants: combatants.value });
const savedSnapshot = ref('');
const isDirty = computed(() => currentSnapshot() !== savedSnapshot.value);

const resetView = () => {
    selectedId.value = null;
    amount.value = '';
    saveError.value = '';
};

const startBlank = () => {
    encounterId.value = null;
    name.value = 'Untitled encounter';
    combatants.value = [];
    round.value = 1;
    activeIndex.value = 0;
    savedSnapshot.value = currentSnapshot();
    resetView();
};

const openSaved = (encounter: Encounter) => {
    const copy = plainCopy(encounter);
    encounterId.value = copy.id;
    name.value = copy.name;
    combatants.value = copy.combatants;
    round.value = copy.round;
    activeIndex.value = copy.activeIndex < copy.combatants.length ? copy.activeIndex : 0;
    savedSnapshot.value = currentSnapshot();
    resetView();
};

const confirmDiscard = () => !isDirty.value || window.confirm('Discard your unsaved changes to this encounter?');

// Browser storage: the fight in progress survives a refresh for guests and signed-in users alike.
const storageKey = trackerStorageKey(user?.id ?? null);

const restoreTracker = (): boolean => {
    const stored = readTracker(storageKey);
    if (!stored) return false;

    // If the saved copy was deleted elsewhere, keep the work as an unsaved encounter.
    const saved = props.savedEncounters.find((e) => e.id === stored.encounterId);
    encounterId.value = saved ? saved.id : null;
    name.value = stored.name;
    combatants.value = stored.combatants;
    round.value = stored.round;
    activeIndex.value = stored.activeIndex;
    savedSnapshot.value = saved ? snapshotOf(saved) : '';
    return true;
};

const persistTracker = () =>
    writeTracker(storageKey, {
        version: 2,
        encounterId: encounterId.value,
        name: name.value,
        combatants: combatants.value,
        round: round.value,
        activeIndex: activeIndex.value,
    });

// Switching encounters from the dropdown.
const switchTo = (event: Event) => {
    const select = event.target as HTMLSelectElement;
    const target = props.savedEncounters.find((e) => String(e.id) === select.value);

    if (!target || !confirmDiscard()) {
        select.value = encounterId.value === null ? '' : String(encounterId.value);
        return;
    }
    openSaved(target);
};

const newEncounter = () => {
    const hasWork = isGuest ? combatants.value.length > 0 : isDirty.value;
    if (hasWork && !window.confirm(isGuest ? 'Clear this encounter and start a new one?' : 'Discard your unsaved changes to this encounter?')) return;
    startBlank();
};

const saveEncounter = () => {
    if (saving.value) return;

    const payload = { name: name.value.trim() || 'Untitled encounter', round: round.value, activeIndex: activeIndex.value, combatants: combatants.value };
    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            saving.value = true;
            saveError.value = '';
        },
        onSuccess: () => {
            encounterId.value = page.props.flash.savedEncounterId ?? encounterId.value;
            name.value = payload.name;
            savedSnapshot.value = currentSnapshot();
        },
        onError: (errors: Record<string, string>) => {
            saveError.value = Object.values(errors)[0] ?? "Couldn't save this encounter.";
        },
        onFinish: () => {
            saving.value = false;
        },
    };

    if (encounterId.value === null) {
        router.post(route('encounters.store'), payload, options);
    } else {
        router.put(route('encounters.update', encounterId.value), payload, options);
    }
};

const deleteEncounter = () => {
    const id = encounterId.value;
    if (id === null || !window.confirm(`Delete "${name.value}"? This can't be undone.`)) return;

    router.delete(route('encounters.destroy', id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: startBlank,
    });
};

const resetEncounter = () => {
    if (!window.confirm(`Reset "${name.value}" to round 1 with full HP and no conditions?`)) return;

    combatants.value.forEach((combatant) => {
        combatant.hp = combatant.maxHp;
        combatant.conditions = [];
    });
    round.value = 1;
    activeIndex.value = 0;
    resetView();
};

// Re-sort by initiative without moving the turn off whoever currently has it.
const sortKeepingTurn = () => {
    const activeId = active.value?.id;
    combatants.value.sort(byInitiative);
    const index = combatants.value.findIndex((c) => c.id === activeId);
    activeIndex.value = index === -1 ? 0 : index;
};

const addCombatants = (creature: Creature, count: number, initiative: number) => {
    const added = combatantsFor(creature, count, initiative, combatants.value);
    combatants.value.push(...added);
    sortKeepingTurn();
    selectedId.value = added[0].id;
};

const setInitiative = (combatant: Combatant, event: Event) => {
    const value = Number.parseInt((event.target as HTMLInputElement).value, 10);
    if (Number.isNaN(value)) return;

    combatant.initiative = Math.max(-100, Math.min(1000, value));
    sortKeepingTurn();
};

const removeCombatant = (combatant: Combatant) => {
    const index = combatants.value.indexOf(combatant);
    if (index === -1) return;

    combatants.value.splice(index, 1);
    // Keep the turn where it was: shift back if someone earlier left; the next in line takes over if it was theirs.
    if (index < activeIndex.value) activeIndex.value--;
    activeIndex.value = Math.min(activeIndex.value, Math.max(0, combatants.value.length - 1));
    selectedId.value = null;
};

const isPlayer = (combatant: Combatant) => creaturesById.value.get(combatant.creatureId)?.kind === 'player';

// Defeated monsters are skipped; players at 0 HP still get a turn (death saves, being revived).
const isOut = (combatant: Combatant) => combatant.hp <= 0 && !isPlayer(combatant);

const step = (direction: 1 | -1) => {
    const count = combatants.value.length;
    if (count === 0 || combatants.value.every(isOut)) return;

    let index = activeIndex.value;
    do {
        index += direction;
        if (index >= count) {
            index = 0;
            round.value++;
        } else if (index < 0) {
            if (round.value === 1) return;
            index = count - 1;
            round.value--;
        }
    } while (isOut(combatants.value[index]));

    activeIndex.value = index;
    selectedId.value = null;
};

const applyHp = (direction: 1 | -1) => {
    const value = Number.parseInt(amount.value, 10);
    if (!selected.value || Number.isNaN(value) || value <= 0) return;

    const target = selected.value;
    target.hp = Math.min(target.maxHp, Math.max(0, target.hp + direction * value));
    amount.value = '';
};

const onAmountKeydown = (event: KeyboardEvent) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    applyHp(event.shiftKey ? 1 : -1);
};

const addCondition = () => {
    if (selected.value && conditionToAdd.value && !selected.value.conditions.includes(conditionToAdd.value)) {
        selected.value.conditions.push(conditionToAdd.value);
    }
    conditionToAdd.value = '';
};

const removeCondition = (combatant: Combatant, condition: string) => {
    combatant.conditions = combatant.conditions.filter((c) => c !== condition);
};

const hpPercent = (combatant: Combatant) => Math.round((combatant.hp / combatant.maxHp) * 100);

const hpBarColor = (combatant: Combatant) => {
    const percent = hpPercent(combatant);
    if (percent > 50) return 'bg-emerald-500';
    if (percent > 25) return 'bg-amber-500';
    return 'bg-red-500';
};

// N / P step turns, unless the DM is typing in a field or a dialog is open.
const onKeydown = (event: KeyboardEvent) => {
    const target = event.target as HTMLElement;
    if (addOpen.value || ['INPUT', 'SELECT', 'TEXTAREA'].includes(target.tagName) || event.metaKey || event.ctrlKey || event.altKey) return;

    if (event.key === 'n') step(1);
    if (event.key === 'p') step(-1);
};

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

// Pick up where this browser left off; otherwise open the most recent saved encounter, or a blank one.
if (!restoreTracker()) {
    if (props.savedEncounters.length) openSaved(props.savedEncounters[0]);
    else startBlank();
}

watch([encounterId, name, combatants, round, activeIndex], persistTracker, { deep: true, immediate: true });
</script>

<template>
    <Head title="Encounters" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Encounter bar -->
            <div class="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-card px-4 py-3">
                <select
                    v-if="!isGuest && (savedEncounters.length || encounterId === null)"
                    :value="encounterId === null ? '' : String(encounterId)"
                    class="h-9 max-w-48 rounded-md border border-input bg-background px-2 text-sm"
                    aria-label="Open a saved encounter"
                    @change="switchTo"
                >
                    <option v-if="encounterId === null" value="">Unsaved encounter</option>
                    <option v-for="e in savedEncounters" :key="e.id" :value="String(e.id)">{{ e.name }}</option>
                </select>
                <Input v-model="name" class="h-9 w-56 font-medium" maxlength="100" aria-label="Encounter name" />
                <span v-if="!isGuest" class="text-xs" :class="isDirty ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'">
                    {{ isDirty ? 'Unsaved changes' : 'Saved' }}
                </span>

                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <Button variant="outline" size="sm" @click="newEncounter">
                        <FilePlus2 />
                        New
                    </Button>
                    <Button v-if="isGuest" variant="outline" size="sm" as-child title="Create a free account to save encounters">
                        <Link :href="route('register')">
                            <Save />
                            Save
                        </Link>
                    </Button>
                    <Button v-else variant="outline" size="sm" :disabled="saving" @click="saveEncounter">
                        <Save />
                        {{ saving ? 'Saving…' : 'Save' }}
                    </Button>
                    <Button v-if="!isGuest && encounterId !== null" variant="outline" size="sm" class="text-red-600 dark:text-red-400" @click="deleteEncounter">
                        <Trash2 />
                        Delete
                    </Button>
                    <Button variant="outline" size="sm" title="Coming soon">
                        <Download />
                        Export
                    </Button>
                </div>
            </div>

            <p v-if="saveError" class="text-sm text-red-600 dark:text-red-400">{{ saveError }}</p>

            <!-- Turn bar -->
            <div class="flex flex-wrap items-center gap-2">
                <Button size="sm" @click="addOpen = true">
                    <Plus />
                    Add combatant
                </Button>
                <span class="ml-2 text-sm text-muted-foreground">Round {{ round }}</span>
                <Button variant="ghost" size="icon" class="h-8 w-8" title="Reset encounter" aria-label="Reset encounter" @click="resetEncounter">
                    <RotateCcw />
                </Button>

                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <Button variant="outline" size="sm" title="Coming soon">
                        <MonitorPlay />
                        Player view
                    </Button>
                    <Button variant="outline" size="sm" title="Previous turn (P)" @click="step(-1)">
                        <ArrowLeft />
                        Previous
                    </Button>
                    <Button size="sm" title="Next turn (N)" @click="step(1)">
                        Next turn
                        <ArrowRight />
                    </Button>
                </div>
            </div>

            <p v-if="isGuest" class="text-sm text-muted-foreground">
                You're using the tracker as a guest with the SRD monsters. Your fight is kept in this browser only, so export an encounter to keep it,
                or
                <Link :href="route('register')" class="font-medium text-foreground underline underline-offset-4">create a free account</Link>
                to save encounters and make your own creatures.
            </p>

            <!-- Empty state -->
            <div v-if="combatants.length === 0" class="rounded-lg border border-dashed border-border px-6 py-16 text-center">
                <h2 class="text-lg font-semibold">Build your encounter</h2>
                <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                    Add monsters, NPCs and players from your compendium, set their initiative, then step through the turns.
                </p>
                <Button class="mt-4" @click="addOpen = true">
                    <Plus />
                    Add combatant
                </Button>
            </div>

            <div v-else class="grid gap-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                <!-- Initiative order -->
                <div class="overflow-hidden rounded-lg border border-border bg-card">
                    <div
                        class="grid grid-cols-[3rem_minmax(0,1fr)_8rem_3rem] gap-3 border-b border-border px-4 py-2 text-xs text-muted-foreground"
                    >
                        <span>Init</span>
                        <span>Name</span>
                        <span>HP</span>
                        <span class="text-right">AC</span>
                    </div>

                    <button
                        v-for="(combatant, index) in combatants"
                        :key="combatant.id"
                        type="button"
                        class="grid w-full grid-cols-[3rem_minmax(0,1fr)_8rem_3rem] items-center gap-3 border-b border-l-4 border-b-border px-4 py-3 text-left text-sm transition-colors last:border-b-0 hover:bg-accent"
                        :class="[
                            index === activeIndex ? 'border-l-primary bg-accent/60' : 'border-l-transparent',
                            selected?.id === combatant.id && index !== activeIndex ? 'bg-accent/40' : '',
                            isOut(combatant) ? 'text-muted-foreground line-through' : '',
                        ]"
                        @click="selectedId = combatant.id"
                    >
                        <span class="font-medium tabular-nums">{{ combatant.initiative }}</span>
                        <span class="flex min-w-0 flex-wrap items-center gap-1.5">
                            <span class="truncate font-medium">{{ combatant.name }}</span>
                            <span v-if="isPlayer(combatant)" class="text-xs text-muted-foreground no-underline">player</span>
                            <span
                                v-for="condition in combatant.conditions"
                                :key="condition"
                                class="rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800 dark:bg-red-950 dark:text-red-300"
                            >
                                {{ condition }}
                            </span>
                        </span>
                        <span class="space-y-1">
                            <span class="block tabular-nums">{{ combatant.hp }} / {{ combatant.maxHp }}</span>
                            <span class="block h-1.5 overflow-hidden rounded-full bg-muted">
                                <span class="block h-full rounded-full" :class="hpBarColor(combatant)" :style="{ width: `${hpPercent(combatant)}%` }" />
                            </span>
                        </span>
                        <span class="text-right tabular-nums">{{ combatant.ac }}</span>
                    </button>
                </div>

                <!-- Selected combatant -->
                <div v-if="selected" class="space-y-4 rounded-lg border border-border bg-card p-4">
                    <div class="flex items-baseline justify-between gap-2">
                        <h2 class="text-lg font-semibold">{{ selected.name }}</h2>
                        <span class="text-xs text-muted-foreground">{{ selected.id === active?.id ? 'Active turn' : 'Selected' }}</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="mr-2 text-sm tabular-nums">
                            <span class="text-muted-foreground">HP</span>
                            <span class="font-medium">{{ selected.hp }} / {{ selected.maxHp }}</span>
                        </span>
                        <Input
                            v-model="amount"
                            type="number"
                            min="1"
                            placeholder="0"
                            class="h-8 w-20"
                            aria-label="Damage or healing amount"
                            @keydown="onAmountKeydown"
                        />
                        <Button variant="outline" size="sm" class="text-red-600 dark:text-red-400" title="Enter" @click="applyHp(-1)">Damage</Button>
                        <Button variant="outline" size="sm" class="text-emerald-600 dark:text-emerald-400" title="Shift + Enter" @click="applyHp(1)">
                            Heal
                        </Button>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <label class="flex items-center gap-2 text-sm">
                            <span class="text-muted-foreground">Initiative</span>
                            <Input
                                :key="selected.id"
                                :model-value="selected.initiative"
                                type="number"
                                class="h-8 w-20"
                                @change="setInitiative(selected, $event)"
                            />
                        </label>
                        <Button variant="ghost" size="sm" class="ml-auto text-red-600 dark:text-red-400" @click="removeCombatant(selected)">
                            <Trash2 />
                            Remove
                        </Button>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            v-for="condition in selected.conditions"
                            :key="condition"
                            class="inline-flex items-center gap-1 rounded bg-red-100 px-2 py-0.5 text-xs text-red-800 dark:bg-red-950 dark:text-red-300"
                        >
                            {{ condition }}
                            <button type="button" :aria-label="`Remove ${condition}`" @click="removeCondition(selected, condition)">
                                <X class="size-3" />
                            </button>
                        </span>
                        <select
                            v-model="conditionToAdd"
                            class="h-8 rounded-md border border-input bg-background px-2 text-xs"
                            aria-label="Add condition"
                            @change="addCondition"
                        >
                            <option value="">+ Condition</option>
                            <option v-for="condition in conditions" :key="condition" :value="condition" :disabled="selected.conditions.includes(condition)">
                                {{ condition }}
                            </option>
                        </select>
                    </div>

                    <StatBlock v-if="selectedCreature" :creature="selectedCreature" class="border-t border-border pt-4" />
                    <p v-else class="border-t border-border pt-4 text-sm text-muted-foreground">
                        This creature is no longer in your compendium, so its stat block isn't available.
                    </p>
                </div>
            </div>

            <p class="text-xs text-muted-foreground">
                Shortcuts: <kbd class="rounded border px-1">N</kbd> next turn, <kbd class="rounded border px-1">P</kbd> previous turn,
                <kbd class="rounded border px-1">Enter</kbd> damage, <kbd class="rounded border px-1">Shift + Enter</kbd> heal.
            </p>
        </div>

        <AddCombatantDialog v-model:open="addOpen" :creatures="creatures" @add="addCombatants" />
    </AppLayout>
</template>
