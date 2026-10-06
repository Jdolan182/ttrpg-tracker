<script setup lang="ts">
// The right-hand panel: everything about the selected combatant (HP, death saves, initiative, states,
// side, conditions, actions, stats), or the combat history.
import CombatHistory from '@/components/CombatHistory.vue';
import StatBlock from '@/components/StatBlock.vue';
import StatGrid from '@/components/StatGrid.vue';
import StatsEditor from '@/components/StatsEditor.vue';
import ConditionEditor from '@/components/tracker/ConditionEditor.vue';
import DeathSaves from '@/components/tracker/DeathSaves.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTrackerContext } from '@/composables/tracker/useTracker';
import { initiativeFormula, limitLabel, sideInfo, switchableSides, usesLeft } from '@/lib/encounter';
import { Brain, EyeClosed, Pencil, ShieldPlus, Trash2 } from 'lucide-vue-next';

const {
    panelTab,
    selected,
    selectedCreature,
    active,
    log,
    isSetup,
    amount,
    onAmountKeydown,
    applyHp,
    applyTempHp,
    setInitiative,
    creatureOf,
    removeCombatant,
    toggleConcentration,
    toggleHidden,
    setSide,
    openAction,
    editingStats,
    statDraft,
    startEditingStats,
    saveStats,
} = useTrackerContext();
</script>

<template>
    <div class="self-start overflow-hidden rounded-xl border border-border bg-card shadow-sm">
        <div class="flex border-b border-border" role="tablist" aria-label="Panel">
            <button
                type="button"
                role="tab"
                :aria-selected="panelTab === 'combatant'"
                class="flex-1 border-b-2 px-4 py-2 text-sm font-medium transition-colors"
                :class="panelTab === 'combatant' ? 'border-primary' : 'border-transparent text-muted-foreground hover:text-foreground'"
                @click="panelTab = 'combatant'"
            >
                {{ selected?.name ?? 'Combatant' }}
            </button>
            <button
                type="button"
                role="tab"
                :aria-selected="panelTab === 'history'"
                class="flex-1 border-b-2 px-4 py-2 text-sm font-medium transition-colors"
                :class="panelTab === 'history' ? 'border-primary' : 'border-transparent text-muted-foreground hover:text-foreground'"
                @click="panelTab = 'history'"
            >
                History
                <span v-if="log.length" class="ml-1 text-xs text-muted-foreground">({{ log.length }})</span>
            </button>
        </div>

        <div v-if="panelTab === 'history'" class="max-h-[70vh] overflow-y-auto p-4">
            <CombatHistory :log="log" />
        </div>

        <div v-else-if="selected" class="space-y-4 p-4">
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="text-lg font-semibold">{{ selected.name }}</h2>
                <span class="text-xs text-muted-foreground">{{ selected.id === active?.id ? 'Active turn' : 'Selected' }}</span>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span class="mr-2 text-sm tabular-nums">
                    <span class="text-muted-foreground">HP</span>
                    <span class="ml-1 font-medium">{{ selected.hp }} / {{ selected.maxHp }}</span>
                    <span v-if="selected.tempHp" class="ml-1 font-medium text-sky-700 dark:text-sky-400" title="Temporary HP, used up first">
                        +{{ selected.tempHp }} temp
                    </span>
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
                <Button variant="outline" size="sm" class="text-emerald-600 dark:text-emerald-400" title="Shift + Enter" @click="applyHp(1)"
                    >Heal</Button
                >
                <Button
                    variant="outline"
                    size="sm"
                    class="text-sky-700 dark:text-sky-400"
                    title="Give temporary HP (keeps whichever is higher)"
                    @click="applyTempHp"
                >
                    <ShieldPlus />
                    Temp HP
                </Button>
            </div>

            <!-- Death saves, for a player character who's down -->
            <DeathSaves v-if="selected.side === 'player' && selected.hp === 0" :combatant="selected" />

            <div class="flex flex-wrap items-center gap-2">
                <label class="flex items-center gap-2 text-sm">
                    <span class="text-muted-foreground">Initiative</span>
                    <Input
                        :key="selected.id"
                        :model-value="selected.initiative ?? ''"
                        type="number"
                        placeholder="–"
                        class="h-8 w-20"
                        @change="setInitiative(selected, $event)"
                    />
                </label>
                <span class="text-xs text-muted-foreground">Rolls {{ initiativeFormula(creatureOf(selected) ?? { stats: selected.stats }) }}</span>
                <Button variant="ghost" size="sm" class="ml-auto text-red-600 dark:text-red-400" @click="removeCombatant(selected)">
                    <Trash2 />
                    Remove
                </Button>
            </div>

            <!-- On/off states: concentrating on a spell, hidden from the players -->
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs transition-colors"
                    :class="
                        selected.concentrating
                            ? 'border-violet-500 bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200'
                            : 'border-border text-muted-foreground hover:bg-accent'
                    "
                    :aria-pressed="!!selected.concentrating"
                    title="Concentrating on a spell: damage prompts a Constitution save"
                    @click="toggleConcentration(selected)"
                >
                    <Brain class="size-3.5" />
                    Concentrating
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs transition-colors"
                    :class="selected.hidden ? 'border-foreground/40 bg-muted text-foreground' : 'border-border text-muted-foreground hover:bg-accent'"
                    :aria-pressed="!!selected.hidden"
                    title="Hidden from players: they won't see this combatant in the player view"
                    @click="toggleHidden(selected)"
                >
                    <EyeClosed class="size-3.5" />
                    Hidden from players
                </button>
            </div>

            <!-- Side: players are fixed, everyone else can switch mid-fight -->
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="text-muted-foreground">Side</span>
                <span v-if="selected.side === 'player'" class="inline-flex items-center gap-1.5">
                    <span class="size-2 rounded-full" :class="sideInfo('player').dot" aria-hidden="true" />
                    Player character
                </span>
                <div v-else class="flex rounded-md border border-border p-0.5" role="group" aria-label="Side">
                    <button
                        v-for="option in switchableSides"
                        :key="option.value"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded px-2.5 py-1 text-xs transition-colors"
                        :class="selected.side === option.value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-accent'"
                        :aria-pressed="selected.side === option.value"
                        @click="setSide(selected, option.value)"
                    >
                        <span class="size-2 rounded-full" :class="option.dot" aria-hidden="true" />
                        {{ option.label }}
                    </button>
                </div>
            </div>

            <ConditionEditor :combatant="selected" />

            <!-- Actions: use one to record it (and any damage or healing) in the history -->
            <section v-if="selectedCreature?.actions.length" class="space-y-2 border-t border-border pt-4">
                <div class="flex items-baseline justify-between">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Actions</h3>
                    <span v-if="isSetup" class="text-xs text-muted-foreground">Start combat to use actions</span>
                </div>
                <div
                    v-for="action in selectedCreature.actions"
                    :key="action.name"
                    class="flex items-start gap-3 rounded-md border border-border p-2.5"
                >
                    <div class="min-w-0 flex-1 text-sm">
                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="font-medium">{{ action.name }}</span>
                            <template v-if="action.uses">
                                <span
                                    v-if="action.uses <= 10"
                                    class="flex gap-0.5"
                                    role="img"
                                    :aria-label="`${usesLeft(selected, action)} of ${action.uses} uses left`"
                                >
                                    <span
                                        v-for="n in action.uses"
                                        :key="n"
                                        class="size-2 rounded-full border border-primary"
                                        :class="n <= (usesLeft(selected, action) ?? 0) ? 'bg-primary' : ''"
                                    />
                                </span>
                                <span class="text-xs text-muted-foreground">
                                    {{ usesLeft(selected, action) }}/{{ action.uses }} left · {{ limitLabel(action) }}
                                </span>
                            </template>
                        </p>
                        <p class="mt-0.5 line-clamp-2 text-muted-foreground" :title="action.description">{{ action.description }}</p>
                    </div>
                    <Button v-if="!isSetup" variant="outline" size="sm" @click="openAction(selected, action)">Use</Button>
                </div>
            </section>

            <StatBlock v-if="selectedCreature" :creature="selectedCreature" hide-actions class="border-t border-border pt-4" />
            <!-- Quick-added: its own stats, editable, since there's no compendium entry behind it -->
            <section v-else-if="selected.creatureId === null" class="space-y-3 border-t border-border pt-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Stats</h3>
                    <Button v-if="!editingStats" variant="ghost" size="sm" class="h-7 px-2 text-xs" @click="startEditingStats(selected)">
                        <Pencil />
                        {{ selected.stats?.length ? 'Edit stats' : 'Add stats' }}
                    </Button>
                </div>
                <template v-if="editingStats">
                    <StatsEditor v-model="statDraft" />
                    <div class="flex justify-end gap-2">
                        <Button variant="outline" size="sm" @click="editingStats = false">Cancel</Button>
                        <Button size="sm" @click="saveStats(selected)">Save stats</Button>
                    </div>
                </template>
                <StatGrid v-else-if="selected.stats?.length" :stats="selected.stats" />
                <p v-else class="text-sm text-muted-foreground">
                    No stats yet. Add some to see their modifiers, and DEX will count when you roll initiative.
                </p>
            </section>
            <p v-else class="border-t border-border pt-4 text-sm text-muted-foreground">
                This creature is no longer in your compendium, so its stat block isn't available.
            </p>
        </div>
    </div>
</template>
