<script setup lang="ts">
// Death saves for a player character who's down: three successes and they're stable, three failures and they're dead.
import { Button } from '@/components/ui/button';
import { useTrackerContext } from '@/composables/tracker/useTracker';
import { isDead, isStable } from '@/lib/encounter';
import type { Combatant } from '@/types/tracker';
import { HeartPulse } from 'lucide-vue-next';

defineProps<{ combatant: Combatant }>();

const { recordDeathSave } = useTrackerContext();
</script>

<template>
    <div class="rounded-lg border border-border bg-muted/50 p-3 text-sm" role="group" aria-label="Death saves">
        <div class="mb-2 flex items-center justify-between gap-2">
            <span class="inline-flex items-center gap-1.5 font-medium">
                <HeartPulse class="size-4 text-red-600 dark:text-red-400" />
                Death saves
            </span>
            <span v-if="isDead(combatant)" class="font-medium text-red-700 dark:text-red-400">Dead</span>
            <span v-else-if="isStable(combatant)" class="font-medium text-emerald-700 dark:text-emerald-400">Stable</span>
            <span v-else class="text-xs text-muted-foreground">Heal them to bring them back up</span>
        </div>
        <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
            <div class="flex items-center gap-2">
                <span class="w-16 text-muted-foreground">Successes</span>
                <span class="flex gap-1" aria-hidden="true">
                    <span
                        v-for="n in 3"
                        :key="n"
                        class="size-3.5 rounded-full border border-emerald-600"
                        :class="n <= (combatant.deathSaves?.successes ?? 0) ? 'bg-emerald-600' : ''"
                    />
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    class="h-7 px-2 text-xs"
                    :disabled="isDead(combatant) || (combatant.deathSaves?.successes ?? 0) >= 3"
                    @click="recordDeathSave(combatant, 'success')"
                >
                    + Success
                </Button>
            </div>
            <div class="flex items-center gap-2">
                <span class="w-16 text-muted-foreground">Failures</span>
                <span class="flex gap-1" aria-hidden="true">
                    <span
                        v-for="n in 3"
                        :key="n"
                        class="size-3.5 rounded-full border border-red-600"
                        :class="n <= (combatant.deathSaves?.failures ?? 0) ? 'bg-red-600' : ''"
                    />
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    class="h-7 px-2 text-xs"
                    :disabled="isDead(combatant) || isStable(combatant)"
                    @click="recordDeathSave(combatant, 'failure')"
                >
                    + Failure
                </Button>
            </div>
        </div>
        <p class="sr-only">{{ combatant.deathSaves?.successes ?? 0 }} successes and {{ combatant.deathSaves?.failures ?? 0 }} failures.</p>
    </div>
</template>
