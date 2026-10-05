<script setup lang="ts">
// All your saved encounters at a glance: open one in the tracker, export it, or delete it.
import BackupMenu from '@/components/BackupMenu.vue';
import BackupNotice from '@/components/BackupNotice.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { confirmAction } from '@/composables/useConfirm';
import AppLayout from '@/layouts/AppLayout.vue';
import { sideInfo } from '@/lib/encounter';
import type { SharedData } from '@/types';
import type { CombatantSide } from '@/types/tracker';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Castle, Download, Play, Search, Swords, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface ListedEncounter {
    id: number;
    name: string;
    campaign: { id: number; name: string } | null;
    round: number;
    combatants: { name: string; side: CombatantSide }[];
    updatedAt: string;
}

const props = defineProps<{
    encounters: ListedEncounter[];
}>();

const page = usePage<SharedData>();
const limit = computed(() => page.props.limits?.encounters);

const search = ref('');
const campaignFilter = ref<number | 'none' | 'all'>('all');

const campaigns = computed(() => {
    const byId = new Map<number, string>();
    for (const e of props.encounters) if (e.campaign) byId.set(e.campaign.id, e.campaign.name);
    return [...byId].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
});

const shown = computed(() => {
    const query = search.value.trim().toLowerCase();
    return props.encounters.filter((e) => {
        if (campaignFilter.value === 'none' && e.campaign) return false;
        if (typeof campaignFilter.value === 'number' && e.campaign?.id !== campaignFilter.value) return false;
        // Search finds an encounter by its name or by who's in it.
        return !query || e.name.toLowerCase().includes(query) || e.combatants.some((c) => c.name.toLowerCase().includes(query));
    });
});

// "3 players · 5 enemies", in the order of the sides.
const sideWords: Record<CombatantSide, [string, string]> = {
    player: ['player', 'players'],
    ally: ['ally', 'allies'],
    neutral: ['neutral', 'neutral'],
    enemy: ['enemy', 'enemies'],
};
const sideCounts = (encounter: ListedEncounter) => {
    const counts = new Map<CombatantSide, number>();
    for (const c of encounter.combatants) counts.set(c.side, (counts.get(c.side) ?? 0) + 1);
    return (Object.keys(sideWords) as CombatantSide[])
        .filter((side) => counts.get(side))
        .map((side) => {
            const count = counts.get(side)!;
            return { side, text: `${count} ${sideWords[side][count === 1 ? 0 : 1]}` };
        });
};

const preview = (encounter: ListedEncounter) => {
    const names = [...new Set(encounter.combatants.map((c) => c.name))];
    return names.length > 6 ? `${names.slice(0, 6).join(', ')} and ${names.length - 6} more` : names.join(', ');
};

const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
const ago = (iso: string) => {
    const seconds = (new Date(iso).getTime() - Date.now()) / 1000;
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['year', 31_536_000],
        ['month', 2_592_000],
        ['week', 604_800],
        ['day', 86_400],
        ['hour', 3_600],
        ['minute', 60],
    ];
    for (const [unit, size] of units) if (Math.abs(seconds) >= size) return relative.format(Math.round(seconds / size), unit);
    return 'just now';
};

const deleteEncounter = async (encounter: ListedEncounter) => {
    const ok = await confirmAction({
        title: `Delete "${encounter.name}"?`,
        message: "The saved encounter is deleted for good. This can't be undone.",
        confirmLabel: 'Delete encounter',
        destructive: true,
        icon: Trash2,
    });
    if (!ok) return;
    router.delete(route('encounters.destroy', encounter.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="Your encounters" />

    <AppLayout>
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-semibold tracking-tight">Your encounters</h1>
                <span
                    v-if="limit"
                    class="text-xs tabular-nums"
                    :class="limit.used >= limit.limit ? 'font-medium text-amber-700 dark:text-amber-400' : 'text-muted-foreground'"
                >
                    {{ limit.used }} of {{ limit.limit }} saved
                </span>
                <div class="ml-auto flex flex-wrap gap-2">
                    <BackupMenu />
                    <Button size="sm" as-child>
                        <Link :href="route('encounters.index')">
                            <Swords />
                            Open the tracker
                        </Link>
                    </Button>
                </div>
            </div>

            <BackupNotice />

            <div v-if="encounters.length" class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-48 flex-1">
                    <Search class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="search" class="pl-8" placeholder="Search by name or combatant" aria-label="Search encounters" />
                </div>
                <select
                    v-if="campaigns.length"
                    v-model="campaignFilter"
                    class="h-10 rounded-md border border-input bg-background px-2 text-sm"
                    aria-label="Filter by campaign"
                >
                    <option value="all">All campaigns</option>
                    <option value="none">Not in a campaign</option>
                    <option v-for="c in campaigns" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>

            <ul v-if="shown.length" class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                <li v-for="encounter in shown" :key="encounter.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Link
                                :href="route('encounters.index', { encounter: encounter.id })"
                                class="font-medium hover:text-primary hover:underline"
                            >
                                {{ encounter.name }}
                            </Link>
                            <span
                                v-if="encounter.round > 0"
                                class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
                                title="Saved mid-fight"
                            >
                                Round {{ encounter.round }}
                            </span>
                            <span v-else class="rounded-full border border-dashed border-border px-2 py-0.5 text-xs text-muted-foreground"
                                >Setting up</span
                            >
                            <Link
                                v-if="encounter.campaign"
                                :href="route('campaigns.show', encounter.campaign.id)"
                                class="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                            >
                                <Castle class="size-3.5" />
                                {{ encounter.campaign.name }}
                            </Link>
                        </div>
                        <p class="mt-1 flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                            <span v-for="count in sideCounts(encounter)" :key="count.side" class="inline-flex items-center gap-1">
                                <span class="size-2 rounded-full" :class="sideInfo(count.side).dot" aria-hidden="true" />
                                {{ count.text }}
                            </span>
                            <span v-if="!encounter.combatants.length">No combatants yet</span>
                            <span>· Updated {{ ago(encounter.updatedAt) }}</span>
                        </p>
                        <p v-if="encounter.combatants.length" class="mt-0.5 truncate text-xs text-muted-foreground/80">{{ preview(encounter) }}</p>
                    </div>
                    <div class="flex shrink-0 gap-1.5">
                        <Button size="sm" variant="outline" as-child>
                            <Link :href="route('encounters.index', { encounter: encounter.id })">
                                <Play />
                                Open
                            </Link>
                        </Button>
                        <Button size="icon" variant="ghost" class="size-9" as-child>
                            <a
                                :href="route('encounters.export', encounter.id)"
                                download
                                :title="`Export ${encounter.name}`"
                                :aria-label="`Export ${encounter.name}`"
                            >
                                <Download />
                            </a>
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            class="size-9 text-red-600 dark:text-red-400"
                            :title="`Delete ${encounter.name}`"
                            :aria-label="`Delete ${encounter.name}`"
                            @click="deleteEncounter(encounter)"
                        >
                            <Trash2 />
                        </Button>
                    </div>
                </li>
            </ul>

            <p v-else-if="encounters.length" class="py-8 text-center text-sm text-muted-foreground">No encounters match.</p>

            <div v-else class="rounded-xl border border-dashed border-border px-6 py-12 text-center text-sm text-muted-foreground">
                <Swords class="mx-auto mb-3 size-8 text-muted-foreground/60" />
                No saved encounters yet. Build one in the tracker and press Save, or import a backup.
            </div>
        </div>
    </AppLayout>
</template>
