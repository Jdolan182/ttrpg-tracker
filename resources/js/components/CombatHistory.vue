<script setup lang="ts">
import { byRound, describe, isTrimmed, MAX_LOG_ENTRIES, type LogEntry, type LogEntryType } from '@/lib/combatLog';
import { computed } from 'vue';

const props = defineProps<{
    log: LogEntry[];
}>();

const rounds = computed(() => byRound(props.log));
const trimmed = computed(() => isTrimmed(props.log));

// Colour the entries that change the state of the fight; bookkeeping stays quiet.
const tone: Partial<Record<LogEntryType, string>> = {
    damage: 'text-red-700 dark:text-red-400',
    down: 'font-medium text-red-700 dark:text-red-400',
    defeated: 'font-medium text-red-700 dark:text-red-400',
    heal: 'text-emerald-700 dark:text-emerald-400',
    revived: 'font-medium text-emerald-700 dark:text-emerald-400',
    combat_started: 'text-muted-foreground',
    sorted: 'text-muted-foreground',
    moved: 'text-muted-foreground',
    initiative_rolled: 'text-muted-foreground',
    temp_hp: 'text-sky-700 dark:text-sky-400',
    died: 'font-medium text-red-700 dark:text-red-400',
    stabilized: 'font-medium text-emerald-700 dark:text-emerald-400',
    hidden: 'text-muted-foreground',
    revealed: 'text-muted-foreground',
    combat_ended: 'text-muted-foreground',
};

const time = (iso: string) => new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
</script>

<template>
    <div class="text-sm">
        <p v-if="rounds.length === 0" class="py-6 text-center text-muted-foreground">
            Nothing yet. Turns, damage, conditions and actions show up here as the fight goes on.
        </p>

        <section v-for="round in rounds" :key="round.round" class="mb-5 last:mb-0">
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {{ round.round === 0 ? 'Setup' : `Round ${round.round}` }}
            </h3>

            <div v-for="turn in round.turns" :key="turn.key" class="mb-3 border-l-2 border-border pl-3 last:mb-0">
                <p v-if="turn.actor" class="font-medium">{{ turn.actor }}'s turn</p>
                <p v-else-if="turn.continued" class="font-medium text-muted-foreground">Earlier in this turn</p>
                <ol class="space-y-0.5">
                    <li v-for="entry in turn.entries" :key="entry.id" class="flex gap-3">
                        <span class="w-10 shrink-0 text-xs tabular-nums leading-5 text-muted-foreground">{{ time(entry.at) }}</span>
                        <span :class="tone[entry.type]">{{ describe(entry) }}</span>
                    </li>
                </ol>
                <p v-if="turn.actor && turn.entries.length === 0" class="text-xs text-muted-foreground">Nothing recorded yet.</p>
            </div>
        </section>

        <p v-if="trimmed" class="mt-4 border-t border-border pt-3 text-xs text-muted-foreground" role="note">
            Older entries were removed to keep the history to the latest {{ MAX_LOG_ENTRIES.toLocaleString() }}.
        </p>
    </div>
</template>
