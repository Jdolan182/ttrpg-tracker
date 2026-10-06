<script setup lang="ts">
// Adding combatants, where the fight is at, and the controls for setting up or running it.
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useTrackerContext } from '@/composables/tracker/useTracker';
import { MAX_COMBATANTS, sideInfo } from '@/lib/encounter';
import {
    ArrowDownWideNarrow,
    ArrowLeft,
    ArrowRight,
    ChevronDown,
    Dices,
    Flame,
    MonitorPlay,
    Play,
    Plus,
    RotateCcw,
    Square,
    Undo2,
    UsersRound,
} from 'lucide-vue-next';

const {
    addOpen,
    missingParty,
    addParty,
    isSetup,
    round,
    active,
    endCombat,
    resetCombat,
    room,
    lastUndo,
    undo,
    showPlayerView,
    combatants,
    rollAll,
    startCombat,
    groupOpen,
    sortByInitiative,
    step,
} = useTrackerContext();

const fullMessage = `This fight has the most combatants it can (${MAX_COMBATANTS}). Remove someone to add more.`;
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <Button size="sm" :disabled="room === 0" :title="room === 0 ? fullMessage : undefined" @click="addOpen = true">
            <Plus />
            Add combatant
        </Button>
        <Button
            v-if="missingParty.length"
            variant="outline"
            size="sm"
            :disabled="room === 0"
            :title="room === 0 ? fullMessage : `Add ${missingParty.map((c) => c.name).join(', ')}`"
            @click="addParty"
        >
            <UsersRound />
            Add party
        </Button>
        <span
            v-if="room <= 10"
            class="text-xs tabular-nums"
            :class="room === 0 ? 'font-medium text-amber-700 dark:text-amber-400' : 'text-muted-foreground'"
        >
            {{ combatants.length }} of {{ MAX_COMBATANTS }} combatants
        </span>
        <!-- Where the fight is at, readable from across the table -->
        <span v-if="isSetup" class="ml-2 rounded-full border border-dashed border-border px-3 py-1 text-sm text-muted-foreground"> Setting up </span>
        <span v-else class="ml-2 inline-flex items-center gap-2 rounded-full bg-primary/10 py-1 pl-1 pr-3 text-sm">
            <span class="rounded-full bg-primary px-2.5 py-0.5 font-display font-semibold text-primary-foreground">Round {{ round }}</span>
            <template v-if="active">
                <span class="size-2 rounded-full" :class="sideInfo(active.side).dot" aria-hidden="true" />
                <span class="font-medium">{{ active.name }}'s turn</span>
            </template>
        </span>
        <Button v-if="!isSetup" variant="ghost" size="sm" title="Back to setup, keeping HP and conditions for whatever comes next" @click="endCombat">
            <Square />
            End combat
        </Button>
        <Button
            v-if="combatants.length"
            variant="ghost"
            size="icon"
            class="size-8"
            title="Reset: everyone back to full HP with nothing used, as if the fight never happened"
            aria-label="Reset the fight"
            @click="resetCombat"
        >
            <RotateCcw />
        </Button>
        <Button
            variant="ghost"
            size="sm"
            :disabled="!lastUndo"
            :title="lastUndo ? `Undo: ${lastUndo.label} (Ctrl+Z)` : 'Nothing to undo'"
            @click="undo"
        >
            <Undo2 />
            Undo
        </Button>

        <div class="ml-auto flex flex-wrap items-center gap-2">
            <Button
                :variant="showPlayerView ? 'default' : 'outline'"
                size="sm"
                :title="showPlayerView ? 'Back to the tracker' : 'See the fight the way players see it'"
                :aria-pressed="showPlayerView"
                @click="showPlayerView = !showPlayerView"
            >
                <MonitorPlay />
                Player view
            </Button>

            <template v-if="isSetup">
                <div class="flex">
                    <Button
                        variant="outline"
                        size="sm"
                        class="rounded-r-none"
                        :disabled="combatants.length === 0"
                        title="Roll d20 + DEX for monsters and NPCs; players enter their own"
                        @click="rollAll({ includePlayers: false })"
                    >
                        <Dices />
                        Roll initiative
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="outline"
                                size="sm"
                                class="rounded-l-none border-l-0 px-2"
                                :disabled="combatants.length === 0"
                                aria-label="More ways to roll"
                            >
                                <ChevronDown />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem @click="rollAll({ includePlayers: false })">Roll for monsters and NPCs</DropdownMenuItem>
                            <DropdownMenuItem @click="rollAll({ includePlayers: true })">Roll for everyone, players too</DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
                <Button size="sm" :disabled="combatants.length === 0" title="Sort by initiative and start round 1" @click="startCombat">
                    <Play />
                    Start combat
                </Button>
            </template>

            <template v-else>
                <Button variant="outline" size="sm" title="Damage or heal several at once, e.g. a fireball" @click="groupOpen = true">
                    <Flame />
                    Damage several
                </Button>
                <Button variant="outline" size="sm" title="Sort the order by initiative" @click="sortByInitiative">
                    <ArrowDownWideNarrow />
                    Sort
                </Button>
                <Button variant="outline" size="sm" title="Previous turn (P)" @click="step(-1)">
                    <ArrowLeft />
                    Previous
                </Button>
                <Button size="sm" title="Next turn (N)" @click="step(1)">
                    Next turn
                    <ArrowRight />
                </Button>
            </template>
        </div>
    </div>
</template>
