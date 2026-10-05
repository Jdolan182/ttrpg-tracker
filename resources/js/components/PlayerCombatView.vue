<script setup lang="ts">
// The fight as players see it: turn order, whose turn it is, and health as far as the campaign
// allows. Used on the campaign page, the full-screen view (e.g. a TV) and the DM's own Player view.
import CombatHistory from '@/components/CombatHistory.vue';
import { conditionIcon, sideInfo } from '@/lib/encounter';
import type { HealthStatus, PlayerViewCombatant, PlayerViewFight } from '@/types/tracker';
import { Brain, ChevronDown, ScrollText, Swords } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        fight: PlayerViewFight | null;
        // Bigger text for reading across the room.
        large?: boolean;
        // What to say when there's no fight.
        emptyText?: string;
    }>(),
    { large: false, emptyText: 'No fight right now. When the DM starts combat, it shows up here.' },
);

const active = computed(() => props.fight?.combatants.find((c) => c.active));

const statusInfo: Record<HealthStatus, { label: string; class: string }> = {
    healthy: { label: 'Healthy', class: 'text-emerald-700 dark:text-emerald-400' },
    bloodied: { label: 'Bloodied', class: 'text-amber-700 dark:text-amber-400' },
    down: { label: 'Down', class: 'text-red-700 dark:text-red-400' },
    dying: { label: 'Dying', class: 'text-red-700 dark:text-red-400' },
    stable: { label: 'Stable', class: 'text-sky-700 dark:text-sky-400' },
    dead: { label: 'Dead', class: 'text-red-700 dark:text-red-400' },
};

const isOut = (c: PlayerViewCombatant) => c.status === 'down' || c.status === 'dead';

const hpPercent = (c: PlayerViewCombatant) => (c.hp !== null && c.maxHp ? Math.round((c.hp / c.maxHp) * 100) : 0);
const barClass = (c: PlayerViewCombatant) => {
    const percent = hpPercent(c);
    if (percent > 50) return 'bg-emerald-500';
    if (percent > 0) return 'bg-amber-500';
    return 'bg-red-500';
};

const roundsLeft = (rounds: number) => (rounds === 1 ? '1 round left' : `${rounds} rounds left`);
</script>

<template>
    <div
        v-if="!fight"
        class="rounded-xl border border-dashed border-border px-6 py-10 text-center text-muted-foreground"
        :class="large ? 'text-lg' : 'text-sm'"
    >
        <Swords class="mx-auto mb-3 text-muted-foreground/60" :class="large ? 'size-10' : 'size-7'" aria-hidden="true" />
        {{ emptyText }}
    </div>

    <div v-else class="space-y-3">
        <!-- Round and turn, readable from across the table -->
        <div class="flex flex-wrap items-center gap-3">
            <span
                class="rounded-full bg-primary font-display font-semibold text-primary-foreground"
                :class="large ? 'px-5 py-1.5 text-2xl' : 'px-3 py-1 text-base'"
            >
                Round {{ fight.round }}
            </span>
            <span v-if="active" class="inline-flex items-center gap-2 font-medium" :class="large ? 'text-3xl' : 'text-lg'">
                <span class="rounded-full" :class="[sideInfo(active.side).dot, large ? 'size-3.5' : 'size-2.5']" aria-hidden="true" />
                {{ active.name }}'s turn
            </span>
            <span class="ml-auto text-muted-foreground" :class="large ? 'text-lg' : 'text-sm'">{{ fight.name }}</span>
        </div>

        <ol class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <li
                v-for="combatant in fight.combatants"
                :key="combatant.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-1.5"
                :class="[large ? 'px-5 py-4' : 'px-4 py-2.5', combatant.active ? 'bg-primary/10' : '', isOut(combatant) ? 'opacity-60' : '']"
                :aria-current="combatant.active ? 'step' : undefined"
            >
                <span class="w-8 shrink-0 text-right font-display tabular-nums text-muted-foreground" :class="large ? 'text-2xl' : 'text-base'">
                    {{ combatant.initiative }}
                </span>
                <span
                    class="shrink-0 rounded-full"
                    :class="[sideInfo(combatant.side).dot, large ? 'size-3.5' : 'size-2.5']"
                    :title="sideInfo(combatant.side).label"
                    aria-hidden="true"
                />
                <span
                    class="min-w-0 flex-1 font-medium"
                    :class="[large ? 'text-2xl' : 'text-base', combatant.status === 'dead' ? 'line-through' : '']"
                >
                    {{ combatant.name }}
                    <Brain
                        v-if="combatant.concentrating"
                        class="ml-1 inline text-violet-600 dark:text-violet-400"
                        :class="large ? 'size-6' : 'size-4'"
                        aria-label="Concentrating"
                    />
                </span>

                <!-- Health: numbers and a bar when shown, otherwise just how they're doing -->
                <span class="flex shrink-0 items-center gap-3" :class="large ? 'text-xl' : 'text-sm'">
                    <template v-if="combatant.hp !== null">
                        <span class="hidden h-2 w-24 overflow-hidden rounded-full bg-muted sm:block" aria-hidden="true">
                            <span class="block h-full rounded-full" :class="barClass(combatant)" :style="{ width: `${hpPercent(combatant)}%` }" />
                        </span>
                        <span class="tabular-nums">
                            {{ combatant.hp }}<span class="text-muted-foreground">/{{ combatant.maxHp }}</span>
                            <span v-if="combatant.tempHp" class="text-sky-700 dark:text-sky-400"> +{{ combatant.tempHp }}</span>
                        </span>
                    </template>
                    <span v-if="combatant.status" class="font-medium" :class="statusInfo[combatant.status].class">
                        {{ statusInfo[combatant.status].label }}
                    </span>
                </span>

                <!-- Conditions on their own line so long lists wrap cleanly -->
                <span v-if="combatant.conditions.length" class="flex basis-full flex-wrap gap-1.5" :class="large ? 'pl-[4.5rem]' : 'pl-[3.75rem]'">
                    <span
                        v-for="condition in combatant.conditions"
                        :key="condition.name"
                        class="inline-flex items-center gap-1 rounded-full border border-border bg-background px-2 py-0.5"
                        :class="large ? 'text-base' : 'text-xs'"
                        :title="condition.rounds ? roundsLeft(condition.rounds) : undefined"
                    >
                        <component :is="conditionIcon(condition.name)" v-if="conditionIcon(condition.name)" class="size-3.5" aria-hidden="true" />
                        {{ condition.name }}
                        <span v-if="condition.rounds" class="text-muted-foreground">· {{ condition.rounds }}</span>
                    </span>
                </span>
            </li>
        </ol>
        <p v-if="!fight.combatants.length" class="text-center text-sm text-muted-foreground">Nobody's in the fight yet.</p>

        <!-- What's happened so far. Closed by default on the big screen, where the order matters most. -->
        <details :open="!large" class="group rounded-xl border border-border bg-card shadow-sm">
            <summary
                class="flex cursor-pointer list-none items-center gap-2 px-4 py-2.5 font-medium [&::-webkit-details-marker]:hidden"
                :class="large ? 'text-lg' : 'text-sm'"
            >
                <ScrollText class="size-4 text-muted-foreground" aria-hidden="true" />
                History
                <ChevronDown class="ml-auto size-4 text-muted-foreground transition-transform group-open:rotate-180" aria-hidden="true" />
            </summary>
            <div class="max-h-[28rem] overflow-y-auto border-t border-border px-4 py-3" :class="large ? 'text-base' : ''">
                <CombatHistory :log="fight.log ?? []" />
            </div>
        </details>
    </div>
</template>
