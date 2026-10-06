<script setup lang="ts">
// The turn order: drag (or Alt+↑/↓) to reorder, click to select, and initiative typed in during setup.
import { Input } from '@/components/ui/input';
import { useTrackerContext } from '@/composables/tracker/useTracker';
import { conditionIcon, hpBarColor, hpPercent, isDead, isOut, isStable, roundsLeft, sideInfo } from '@/lib/encounter';
import { Brain, EyeClosed, GripVertical, Skull } from 'lucide-vue-next';
import { VueDraggable } from 'vue-draggable-plus';

const { combatants, isSetup, activeIndex, selected, selectedId, setInitiative, onDragStart, onDragEnd } = useTrackerContext();
</script>

<template>
    <div class="self-start overflow-hidden rounded-xl border border-border bg-card shadow-sm">
        <div
            class="grid grid-cols-[1rem_2.5rem_minmax(0,1fr)_4.75rem_1.75rem] gap-2 border-b border-border bg-muted/60 px-3 py-2 text-xs font-medium uppercase tracking-wide text-muted-foreground sm:grid-cols-[1.25rem_3.5rem_minmax(0,1fr)_8rem_3rem] sm:gap-3"
        >
            <!-- Holds the drag-handle column; an sr-only span would drop out of the grid. -->
            <span aria-hidden="true" />
            <span>Init</span>
            <span>Name</span>
            <span>HP</span>
            <span class="text-right">AC</span>
        </div>

        <!-- Drag by the handle to reorder; Alt+↑/↓ moves the selected combatant. -->
        <VueDraggable v-model="combatants" handle=".drag-handle" :animation="150" ghost-class="opacity-40" @start="onDragStart" @end="onDragEnd">
            <div
                v-for="(combatant, index) in combatants"
                :key="combatant.id"
                data-combatant-row
                class="grid w-full cursor-pointer grid-cols-[1rem_2.5rem_minmax(0,1fr)_4.75rem_1.75rem] items-center gap-2 border-b border-l-4 border-b-border py-3 pl-2 pr-3 text-left text-sm transition-colors last:border-b-0 hover:bg-accent sm:grid-cols-[1.25rem_3.5rem_minmax(0,1fr)_8rem_3rem] sm:gap-3"
                :class="[
                    !isSetup && index === activeIndex ? 'border-l-primary bg-primary/[0.07]' : 'border-l-transparent',
                    selected?.id === combatant.id && (isSetup || index !== activeIndex) ? 'bg-accent/70' : '',
                    isOut(combatant) ? 'text-muted-foreground' : '',
                ]"
                @click="selectedId = combatant.id"
            >
                <button
                    type="button"
                    class="drag-handle flex cursor-grab touch-none items-center justify-center rounded text-muted-foreground hover:text-foreground active:cursor-grabbing"
                    :aria-label="`Reorder ${combatant.name} (drag, or select and press Alt+Up or Alt+Down)`"
                    @click.stop="selectedId = combatant.id"
                >
                    <GripVertical class="size-4" />
                </button>
                <Input
                    v-if="isSetup"
                    :model-value="combatant.initiative ?? ''"
                    type="number"
                    placeholder="–"
                    class="h-8 w-full px-1 text-center tabular-nums sm:w-14 sm:px-2"
                    :aria-label="`Initiative for ${combatant.name}`"
                    @click.stop="selectedId = combatant.id"
                    @change="setInitiative(combatant, $event)"
                />
                <span v-else class="font-medium tabular-nums">{{ combatant.initiative ?? '–' }}</span>
                <span class="flex min-w-0 flex-wrap items-center gap-1.5">
                    <span
                        class="size-2 shrink-0 rounded-full"
                        :class="sideInfo(combatant.side).dot"
                        :title="sideInfo(combatant.side).label"
                        aria-hidden="true"
                    />
                    <button
                        type="button"
                        class="truncate text-left font-medium"
                        :class="[isOut(combatant) ? 'line-through' : '', combatant.hidden ? 'italic opacity-70' : '']"
                        @click.stop="selectedId = combatant.id"
                    >
                        {{ combatant.name }}
                    </button>
                    <span class="sr-only">({{ sideInfo(combatant.side).label }})</span>
                    <span v-if="combatant.side !== 'enemy'" class="text-xs" :class="sideInfo(combatant.side).text">
                        {{ sideInfo(combatant.side).label.toLowerCase() }}
                    </span>
                    <span v-if="combatant.hidden" title="Hidden from players" role="img" aria-label="Hidden from players">
                        <EyeClosed class="size-3.5 text-muted-foreground" aria-hidden="true" />
                    </span>
                    <span v-if="combatant.concentrating" title="Concentrating" role="img" aria-label="Concentrating">
                        <Brain class="size-3.5 text-violet-600 dark:text-violet-400" aria-hidden="true" />
                    </span>
                    <span v-if="isDead(combatant)" class="inline-flex items-center gap-0.5 text-xs font-medium text-red-700 dark:text-red-400">
                        <Skull class="size-3.5" aria-hidden="true" />
                        dead
                    </span>
                    <span
                        v-else-if="combatant.side === 'player' && combatant.hp === 0"
                        class="text-xs tabular-nums"
                        :title="`Death saves: ${combatant.deathSaves?.successes ?? 0} successes, ${combatant.deathSaves?.failures ?? 0} failures`"
                    >
                        <template v-if="isStable(combatant)"><span class="text-emerald-700 dark:text-emerald-400">stable</span></template>
                        <template v-else>
                            <span class="text-emerald-700 dark:text-emerald-400">✓{{ combatant.deathSaves?.successes ?? 0 }}</span>
                            <span class="ml-1 text-red-700 dark:text-red-400">✗{{ combatant.deathSaves?.failures ?? 0 }}</span>
                        </template>
                    </span>
                    <span v-if="combatant.conditions.length" class="flex items-center gap-1 text-red-600 dark:text-red-400">
                        <span
                            v-for="condition in combatant.conditions"
                            :key="condition"
                            class="inline-flex items-center"
                            :title="combatant.durations?.[condition] ? `${condition} (${roundsLeft(combatant.durations[condition])})` : condition"
                            role="img"
                            :aria-label="combatant.durations?.[condition] ? `${condition}, ${roundsLeft(combatant.durations[condition])}` : condition"
                        >
                            <component :is="conditionIcon(condition)" v-if="conditionIcon(condition)" class="size-3.5" aria-hidden="true" />
                            <span v-else class="text-xs" aria-hidden="true">{{ condition }}</span>
                            <sub v-if="combatant.durations?.[condition]" class="text-[10px] font-medium tabular-nums" aria-hidden="true">
                                {{ combatant.durations[condition] }}
                            </sub>
                        </span>
                    </span>
                </span>
                <span class="space-y-1">
                    <span class="block tabular-nums">
                        {{ combatant.hp }} / {{ combatant.maxHp }}
                        <span v-if="combatant.tempHp" class="text-xs font-medium text-sky-700 dark:text-sky-400" title="Temporary HP">
                            +{{ combatant.tempHp }}
                        </span>
                    </span>
                    <span class="block h-2 overflow-hidden rounded-full bg-muted">
                        <span class="block h-full rounded-full" :class="hpBarColor(combatant)" :style="{ width: `${hpPercent(combatant)}%` }" />
                    </span>
                </span>
                <span class="text-right tabular-nums">{{ combatant.ac }}</span>
            </div>
        </VueDraggable>
    </div>
</template>
