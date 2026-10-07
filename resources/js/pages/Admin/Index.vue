<script setup lang="ts">
// The owner's view of how the site is used: totals and daily counts from App\Support\SiteStats, never
// anyone's names or content. Only accounts in ADMIN_EMAILS can open it.
import BarChart from '@/components/admin/BarChart.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Day {
    day: string;
    visitors: number;
    accounts: number;
    signups: number;
    peakLiveTables: number;
}

const props = defineProps<{
    summary: {
        days: number;
        accounts: number;
        verified: number;
        new: number;
        active: number;
        creatures: number;
        creatureMakers: number;
        creaturesByKind: Record<string, number>;
        encounters: number;
        encounterMakers: number;
        inCombat: number;
        biggestFight: number;
        campaigns: number;
        players: number;
        live: number;
    };
    traffic: {
        history: Day[];
        today: Day;
        averageVisitors7: number;
        averageVisitors30: number;
        trackedDays: number;
        activeAccounts: { today: number; week: number; month: number };
        peakLiveTables: number;
    };
}>();

const short = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short' });
const long = new Intl.DateTimeFormat(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
// Dates come as "2026-10-07"; read them as local days, not UTC midnight.
const asDate = (day: string) => new Date(`${day}T12:00:00`);

const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`;

const visitorBars = computed(() =>
    props.traffic.history.map((d) => ({
        label: short.format(asDate(d.day)),
        value: d.visitors,
        details: [`${d.accounts} logged in`],
    })),
);
const signupBars = computed(() => props.traffic.history.map((d) => ({ label: short.format(asDate(d.day)), value: d.signups })));
const liveBars = computed(() => props.traffic.history.map((d) => ({ label: short.format(asDate(d.day)), value: d.peakLiveTables })));
const signups30 = computed(() => props.traffic.history.reduce((sum, d) => sum + d.signups, 0));

const headline = computed(() => [
    { label: 'Visitors today', value: props.traffic.today.visitors, note: `${props.traffic.today.accounts} logged in` },
    { label: 'Daily visitors, 7-day average', value: props.traffic.averageVisitors7, note: `30-day: ${props.traffic.averageVisitors30}` },
    {
        label: 'Accounts active this month',
        value: props.traffic.activeAccounts.month,
        note: `${props.traffic.activeAccounts.week} this week, ${props.traffic.activeAccounts.today} today`,
    },
    { label: 'Sign-ups, last 30 days', value: signups30.value, note: `${props.summary.new} in the last 7 days` },
    { label: 'Most tables live at once', value: props.traffic.peakLiveTables, note: `last 30 days, ${props.summary.live} live now` },
]);

const kinds = computed(() =>
    Object.entries(props.summary.creaturesByKind)
        .map(([kind, n]) => `${n} ${kind === 'npc' ? 'NPC' : kind}${n === 1 || kind === 'npc' ? '' : 's'}`)
        .join(', '),
);

const content = computed(() => [
    { label: 'Accounts', value: props.summary.accounts, note: `${props.summary.verified} verified` },
    { label: 'Homebrew creatures', value: props.summary.creatures, note: kinds.value || `by ${plural(props.summary.creatureMakers, 'account')}` },
    { label: 'Saved encounters', value: props.summary.encounters, note: `by ${plural(props.summary.encounterMakers, 'account')}` },
    { label: 'Campaigns', value: props.summary.campaigns, note: `${plural(props.summary.players, 'player')} joined` },
    { label: 'Biggest fight', value: props.summary.biggestFight, note: 'combatants' },
]);

const newestFirst = computed(() => [...props.traffic.history].reverse());
</script>

<template>
    <Head title="Site stats" />

    <AppLayout>
        <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Site stats</h1>
                <p class="text-sm text-muted-foreground">
                    Totals only, never anyone's content. Visitors are counted once a day each, without cookies or stored addresses<template
                        v-if="traffic.trackedDays"
                        >; counting has run for {{ plural(traffic.trackedDays, 'day') }}</template
                    >.
                </p>
            </div>

            <!-- The numbers to glance at -->
            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div v-for="tile in headline" :key="tile.label" class="rounded-xl border border-border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">{{ tile.label }}</p>
                    <p class="mt-1 font-display text-3xl font-semibold tabular-nums">{{ tile.value }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">{{ tile.note }}</p>
                </div>
            </section>

            <!-- Last 30 days -->
            <section class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-border bg-card p-4 shadow-sm lg:col-span-2">
                    <h2 class="font-semibold">Visitors per day</h2>
                    <p class="mb-3 text-xs text-muted-foreground">Different people each day, with or without an account. Last 30 days.</p>
                    <BarChart :bars="visitorBars" unit="visitors" />
                </div>
                <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                    <h2 class="font-semibold">Sign-ups per day</h2>
                    <p class="mb-3 text-xs text-muted-foreground">New accounts. Last 30 days.</p>
                    <BarChart :bars="signupBars" unit="sign-ups" />
                </div>
                <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                    <h2 class="font-semibold">Tables in live combat at once</h2>
                    <p class="mb-3 text-xs text-muted-foreground">
                        The day's busiest moment for the player view. Around 50 is when to look at speeding it up.
                    </p>
                    <BarChart :bars="liveBars" unit="tables" />
                </div>
            </section>

            <!-- What people have made -->
            <section class="space-y-3">
                <h2 class="font-semibold">What people have made</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <div v-for="tile in content" :key="tile.label" class="rounded-xl border border-border bg-card p-4 shadow-sm">
                        <p class="text-xs text-muted-foreground">{{ tile.label }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums">{{ tile.value }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">{{ tile.note }}</p>
                    </div>
                </div>
            </section>

            <!-- The charts as a table -->
            <details class="rounded-xl border border-border bg-card shadow-sm">
                <summary class="cursor-pointer px-4 py-3 font-semibold">Day by day</summary>
                <div class="overflow-x-auto border-t border-border">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">Day</th>
                                <th class="px-4 py-2 text-right font-medium">Visitors</th>
                                <th class="px-4 py-2 text-right font-medium">Logged in</th>
                                <th class="px-4 py-2 text-right font-medium">Sign-ups</th>
                                <th class="px-4 py-2 text-right font-medium">Live tables (peak)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in newestFirst" :key="d.day" class="border-t border-border/60">
                                <td class="px-4 py-1.5">{{ long.format(asDate(d.day)) }}</td>
                                <td class="px-4 py-1.5 text-right tabular-nums">{{ d.visitors }}</td>
                                <td class="px-4 py-1.5 text-right tabular-nums">{{ d.accounts }}</td>
                                <td class="px-4 py-1.5 text-right tabular-nums">{{ d.signups }}</td>
                                <td class="px-4 py-1.5 text-right tabular-nums">{{ d.peakLiveTables }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
    </AppLayout>
</template>
