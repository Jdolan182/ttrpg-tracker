<script setup lang="ts">
// Which encounter is open, its name and campaign, whether it's saved, and New / Save / Delete / Export.
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTrackerContext } from '@/composables/tracker/useTracker';
import { Link } from '@inertiajs/vue3';
import { Download, FilePlus2, Save, Trash2 } from 'lucide-vue-next';

const {
    isGuest,
    savedEncounters,
    encounterId,
    loadingEncounter,
    switchTo,
    name,
    isDirty,
    limits,
    campaigns,
    campaignId,
    pickedCampaign,
    campaign,
    isSetup,
    liveStatus,
    newEncounter,
    saving,
    saveEncounter,
    deleteEncounter,
    exportFight,
    saveError,
} = useTrackerContext();
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-card px-4 py-3 shadow-sm">
        <select
            v-if="!isGuest && (savedEncounters.length || encounterId === null)"
            :value="encounterId === null ? '' : String(encounterId)"
            :disabled="loadingEncounter"
            class="h-9 max-w-48 rounded-md border border-input bg-background px-2 text-sm disabled:opacity-50"
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
        <!-- Only matters for a new encounter: saving an existing one never counts against the limit -->
        <span
            v-if="encounterId === null && limits"
            class="text-xs tabular-nums"
            :class="limits.encounters.used >= limits.encounters.limit ? 'font-medium text-amber-700 dark:text-amber-400' : 'text-muted-foreground'"
        >
            · {{ limits.encounters.used }} of {{ limits.encounters.limit }} encounters saved
        </span>
        <select
            v-if="!isGuest && (campaigns.length || campaignId !== null)"
            v-model="pickedCampaign"
            class="h-9 max-w-48 rounded-md border border-input bg-background px-2 text-sm"
            aria-label="Campaign"
        >
            <option :value="null">No campaign</option>
            <option v-for="c in campaigns" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <span v-if="campaign && !isSetup && liveStatus === 'live'" class="inline-flex items-center gap-1.5 text-xs font-medium text-primary">
            <span class="size-2 animate-pulse rounded-full bg-primary" aria-hidden="true" />
            Live for players
        </span>
        <span v-else-if="campaign && !isSetup && liveStatus === 'error'" class="text-xs font-medium text-red-600 dark:text-red-400">
            Couldn't update players
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
            <Button
                v-if="!isGuest && encounterId !== null"
                variant="outline"
                size="sm"
                class="text-red-600 dark:text-red-400"
                @click="deleteEncounter"
            >
                <Trash2 />
                Delete
            </Button>
            <Button
                variant="outline"
                size="sm"
                :title="
                    isGuest ? 'Save this fight as a file. Import it after making an account.' : 'Save this fight as a file, unsaved changes included'
                "
                @click="exportFight"
            >
                <Download />
                Export
            </Button>
        </div>
    </div>

    <p v-if="saveError" class="text-sm text-red-600 dark:text-red-400">{{ saveError }}</p>
</template>
