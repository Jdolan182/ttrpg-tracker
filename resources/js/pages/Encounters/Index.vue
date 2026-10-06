<script setup lang="ts">
// The encounter tracker. The logic lives in composables/tracker (put together by useTracker) and the
// screen's parts in components/tracker; this page provides the one to the other and lays them out.
import AddCombatantDialog from '@/components/AddCombatantDialog.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import GroupDamageDialog from '@/components/GroupDamageDialog.vue';
import PlayerCombatView from '@/components/PlayerCombatView.vue';
import CombatantPanel from '@/components/tracker/CombatantPanel.vue';
import EncounterBar from '@/components/tracker/EncounterBar.vue';
import InitiativeList from '@/components/tracker/InitiativeList.vue';
import TurnBar from '@/components/tracker/TurnBar.vue';
import { Button } from '@/components/ui/button';
import UseActionDialog from '@/components/UseActionDialog.vue';
import type { TrackerProps } from '@/composables/tracker/useEncounterFile';
import { provideTracker } from '@/composables/tracker/useTracker';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { Brain, ExternalLink, MonitorPlay, Plus } from 'lucide-vue-next';

const props = defineProps<TrackerProps>();

const {
    isGuest,
    showPlayerView,
    campaign,
    enemyHp,
    openPlayerWindow,
    localPlayerView,
    pendingChecks,
    resolveConcentration,
    combatants,
    addOpen,
    room,
    creatures,
    addCombatants,
    quickAdd,
    groupOpen,
    selectedId,
    applyGroup,
    actionOpen,
    actionActor,
    chosenAction,
    chosenStatus,
    useAction,
} = provideTracker(props);
</script>

<template>
    <Head title="Encounters" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <EncounterBar />
            <TurnBar />

            <p v-if="isGuest" class="text-sm text-muted-foreground">
                You're using the tracker as a guest with the SRD monsters. Your fight is kept in this browser only, so export an encounter to keep it,
                or
                <Link :href="route('register')" class="font-medium text-foreground underline underline-offset-4">create a free account</Link>
                to save encounters and make your own creatures.
            </p>

            <!-- Player view: the DM's screen shows what players see. The turn buttons and shortcuts still work. -->
            <div v-if="showPlayerView" class="space-y-3">
                <div class="flex flex-wrap items-center gap-2 rounded-xl border border-primary/30 bg-primary/5 px-4 py-2.5 text-sm">
                    <MonitorPlay class="size-4 shrink-0 text-primary" />
                    <span class="flex-1">
                        This is what players see{{ campaign ? ` in ${campaign.name}` : '' }}. Hidden combatants are left out{{
                            enemyHp === 'exact'
                                ? ''
                                : enemyHp === 'bands'
                                  ? ' and enemy HP shows as Healthy, Bloodied or Down'
                                  : ' and enemy HP is hidden'
                        }}.
                    </span>
                    <Button size="sm" variant="outline" class="h-7 bg-background" @click="openPlayerWindow">
                        <ExternalLink />
                        Open in a new window
                    </Button>
                    <Button size="sm" variant="outline" class="h-7 bg-background" @click="showPlayerView = false">Back to the tracker</Button>
                </div>
                <PlayerCombatView :fight="localPlayerView" large empty-text="Players don't see setup. Start combat and the fight appears here." />
            </div>

            <template v-else>
                <!-- Concentration saves to resolve, one per hit on someone concentrating -->
                <div
                    v-for="check in pendingChecks"
                    :key="check.key"
                    class="flex flex-wrap items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/60 dark:text-amber-100"
                    role="alert"
                >
                    <Brain class="size-4 shrink-0" />
                    <span class="flex-1">
                        <span class="font-medium">{{ check.combatant?.name }}</span> took damage while concentrating: Constitution save, DC
                        <span class="font-semibold tabular-nums">{{ check.dc }}</span
                        >.
                    </span>
                    <Button size="sm" variant="outline" class="h-7 bg-background" @click="resolveConcentration(check, true)">Kept it</Button>
                    <Button
                        size="sm"
                        variant="outline"
                        class="h-7 bg-background text-red-600 dark:text-red-400"
                        @click="resolveConcentration(check, false)"
                    >
                        Lost it
                    </Button>
                </div>

                <!-- Empty state -->
                <div v-if="combatants.length === 0" class="rounded-xl border border-dashed border-border bg-card/60 px-6 py-16 text-center">
                    <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-primary/10 text-primary">
                        <AppLogoIcon class="size-7" />
                    </div>
                    <h2 class="text-2xl font-semibold">Build your encounter</h2>
                    <p class="mx-auto mt-2 max-w-md text-sm text-muted-foreground">
                        Add monsters, NPCs and players from your compendium, roll initiative, then start combat and step through the turns.
                    </p>
                    <Button class="mt-4" @click="addOpen = true">
                        <Plus />
                        Add combatant
                    </Button>
                </div>

                <div v-else class="grid gap-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                    <InitiativeList />
                    <CombatantPanel />
                </div>
            </template>

            <p class="hidden text-xs text-muted-foreground sm:block">
                Shortcuts: <kbd class="rounded border px-1">N</kbd> next turn, <kbd class="rounded border px-1">P</kbd> previous turn,
                <kbd class="rounded border px-1">Enter</kbd> damage, <kbd class="rounded border px-1">Shift + Enter</kbd> heal,
                <kbd class="rounded border px-1">Ctrl + Z</kbd> undo.
            </p>
        </div>

        <AddCombatantDialog v-model:open="addOpen" :creatures="creatures" :room="room" @add="addCombatants" @quick-add="quickAdd" />
        <GroupDamageDialog v-model:open="groupOpen" :combatants="combatants" :preselected="selectedId ? [selectedId] : []" @apply="applyGroup" />
        <UseActionDialog
            v-model:open="actionOpen"
            :actor="actionActor"
            :action="chosenAction"
            :status="chosenStatus"
            :combatants="combatants"
            @use="useAction"
        />
    </AppLayout>
</template>
