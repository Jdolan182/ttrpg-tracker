<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useStatDisplay } from '@/composables/useStatDisplay';
import AppLayout from '@/layouts/AppLayout.vue';
import { limitPeriods } from '@/lib/encounter';
import { formatModifier, modifier } from '@/lib/stats';
import { plainCopy } from '@/lib/utils';
import type { Creature, CreatureEntry, CreatureKind, CreatureStat, LimitPeriod } from '@/types/tracker';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Plus, X } from 'lucide-vue-next';

// An action as edited: `uses` is '' while blank (unlimited), and `per` is kept even then so it's
// remembered if a limit is typed back in.
interface ActionForm extends CreatureEntry {
    uses: number | string;
    per: LimitPeriod;
}

const props = defineProps<{
    // The creature being edited, or null when creating.
    creature: Creature | null;
    // A creature to copy from when duplicating.
    template: Creature | null;
}>();

const isEditing = props.creature !== null;
const base = props.creature ?? props.template;

const defaultStats: CreatureStat[] = ['STR', 'DEX', 'CON', 'INT', 'WIS', 'CHA'].map((label) => ({ label, value: 10 }));

const form = useForm({
    kind: (base?.kind ?? 'monster') as CreatureKind,
    name: props.template ? `${props.template.name} (copy)` : (base?.name ?? ''),
    summary: base?.summary ?? '',
    rating: base?.rating ?? '',
    hp: base?.hp ?? 10,
    ac: base?.ac ?? 10,
    speed: base?.speed ?? '30 ft.',
    stats: plainCopy(base?.stats ?? defaultStats),
    traits: plainCopy(base?.traits ?? []) as CreatureEntry[],
    actions: plainCopy(base?.actions ?? []).map(
        (action): ActionForm => ({ name: action.name, description: action.description, uses: action.uses ?? '', per: action.per ?? 'day' }),
    ),
});

const addEntry = (list: 'traits' | 'actions') => {
    if (list === 'actions') form.actions.push({ name: '', description: '', uses: '', per: 'day' });
    else form.traits.push({ name: '', description: '' });
};

const entryError = (list: 'traits' | 'actions', index: number) =>
    listError(list, index, 'name') ??
    listError(list, index, 'description') ??
    (list === 'actions' ? (listError(list, index, 'uses') ?? listError(list, index, 'per')) : undefined);

const kindOptions: { value: CreatureKind; label: string }[] = [
    { value: 'monster', label: 'Monster' },
    { value: 'npc', label: 'NPC' },
    { value: 'player', label: 'Player character' },
];

const submit = () => {
    // A blank limit means unlimited: send no period with it.
    const withLimits = form.transform((data) => ({
        ...data,
        actions: data.actions.map((action) => ({
            ...action,
            uses: action.uses === '' ? null : action.uses,
            per: action.uses === '' ? null : action.per,
        })),
    }));

    if (props.creature) {
        withLimits.put(route('creatures.update', props.creature.id), { preserveScroll: true });
    } else {
        withLimits.post(route('creatures.store'), { preserveScroll: true });
    }
};

const statDisplay = useStatDisplay();

// Live modifier next to each stat value while editing; blank until the value is a whole number.
const modifierHint = (value: number | string) => {
    const score = Number(value);
    return value !== '' && Number.isInteger(score) ? formatModifier(modifier(score)) : '';
};

// Errors for list fields come back keyed by position, e.g. "stats.2.label".
const listError = (list: string, index: number, field: string) => (form.errors as Record<string, string>)[`${list}.${index}.${field}`];

const textareaClass =
    'flex min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2';
</script>

<template>
    <Head :title="isEditing ? `Edit ${creature?.name}` : 'New creature'" />

    <AppLayout>
        <form class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4" @submit.prevent="submit">
            <div class="flex flex-wrap items-center gap-3">
                <Button variant="ghost" size="icon" as-child aria-label="Back to compendium">
                    <Link :href="route('compendium.index', creature ? { creature: creature.id } : {})">
                        <ArrowLeft />
                    </Link>
                </Button>
                <h1 class="text-2xl font-semibold tracking-tight">{{ isEditing ? `Edit ${creature?.name}` : 'New creature' }}</h1>
            </div>

            <!-- Basics -->
            <section class="grid gap-4 rounded-lg border border-border bg-card p-4 sm:grid-cols-2">
                <div class="grid gap-1.5 sm:col-span-2">
                    <Label for="name">Name</Label>
                    <Input id="name" v-model="form.name" required maxlength="100" placeholder="Goblin shaman" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="kind">Type</Label>
                    <select id="kind" v-model="form.kind" class="h-10 rounded-md border border-input bg-background px-3 text-sm">
                        <option v-for="option in kindOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                    <InputError :message="form.errors.kind" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="rating">Rating</Label>
                    <Input id="rating" v-model="form.rating" maxlength="50" placeholder="CR 2, Level 5, Tier 1" />
                    <InputError :message="form.errors.rating" />
                </div>
                <div class="grid gap-1.5 sm:col-span-2">
                    <Label for="summary">Description</Label>
                    <Input id="summary" v-model="form.summary" maxlength="255" placeholder="Small humanoid (goblinoid), neutral evil" />
                    <InputError :message="form.errors.summary" />
                </div>
            </section>

            <!-- Combat numbers -->
            <section class="grid gap-4 rounded-lg border border-border bg-card p-4 sm:grid-cols-3">
                <div class="grid gap-1.5">
                    <Label for="hp">Hit points</Label>
                    <Input id="hp" v-model="form.hp" type="number" min="1" required />
                    <InputError :message="form.errors.hp" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="ac">Armor class</Label>
                    <Input id="ac" v-model="form.ac" type="number" min="0" required />
                    <InputError :message="form.errors.ac" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="speed">Speed</Label>
                    <Input id="speed" v-model="form.speed" maxlength="100" placeholder="30 ft., fly 60 ft." />
                    <InputError :message="form.errors.speed" />
                </div>
            </section>

            <!-- Stats -->
            <section class="space-y-3 rounded-lg border border-border bg-card p-4">
                <div>
                    <h2 class="font-semibold">Stats</h2>
                    <p class="text-sm text-muted-foreground">Name them whatever your game uses, e.g. STR or Might.</p>
                </div>
                <div class="grid gap-2 sm:grid-cols-2">
                    <div v-for="(stat, index) in form.stats" :key="index" class="space-y-1">
                        <div class="flex items-center gap-2">
                            <Input v-model="stat.label" maxlength="20" required class="flex-1" :aria-label="`Stat ${index + 1} name`" />
                            <Input v-model="stat.value" type="number" required class="w-24" :aria-label="`Stat ${index + 1} value`" />
                            <span v-if="statDisplay !== 'score'" class="w-8 text-sm tabular-nums text-muted-foreground" aria-live="polite">
                                {{ modifierHint(stat.value) }}
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="`Remove ${stat.label || 'stat'}`"
                                @click="form.stats.splice(index, 1)"
                            >
                                <X />
                            </Button>
                        </div>
                        <InputError :message="listError('stats', index, 'label') ?? listError('stats', index, 'value')" />
                    </div>
                </div>
                <Button type="button" variant="outline" size="sm" @click="form.stats.push({ label: '', value: 10 })">
                    <Plus />
                    Add stat
                </Button>
            </section>

            <!-- Traits and actions share the same name + description editor -->
            <section v-for="list in ['traits', 'actions'] as const" :key="list" class="space-y-3 rounded-lg border border-border bg-card p-4">
                <div>
                    <h2 class="font-semibold">{{ list === 'traits' ? 'Traits' : 'Actions' }}</h2>
                    <p class="text-sm text-muted-foreground">
                        {{ list === 'traits' ? 'Passive abilities, resistances and senses.' : 'Attacks and anything it does on its turn.' }}
                    </p>
                </div>
                <div v-for="(entry, index) in form[list]" :key="index" class="space-y-2 rounded-md border border-border p-3">
                    <div class="flex items-center gap-2">
                        <Input
                            v-model="entry.name"
                            maxlength="100"
                            required
                            :placeholder="list === 'traits' ? 'Pack Tactics' : 'Scimitar'"
                            :aria-label="`${list === 'traits' ? 'Trait' : 'Action'} ${index + 1} name`"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :aria-label="`Remove ${entry.name || 'entry'}`"
                            @click="form[list].splice(index, 1)"
                        >
                            <X />
                        </Button>
                    </div>
                    <textarea
                        v-model="entry.description"
                        maxlength="2000"
                        required
                        :class="textareaClass"
                        :placeholder="
                            list === 'traits'
                                ? 'Advantage on attacks when an ally is within 5 ft.'
                                : 'Melee attack: +4 to hit. Hit: 5 (1d6 + 2) slashing damage.'
                        "
                        :aria-label="`${list === 'traits' ? 'Trait' : 'Action'} ${index + 1} description`"
                    />
                    <!-- Optional limit on how often an action can be used, e.g. 3 per day -->
                    <div v-if="list === 'actions'" class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="text-muted-foreground">Limited to</span>
                        <Input
                            v-model="form.actions[index].uses"
                            type="number"
                            min="1"
                            max="99"
                            placeholder="∞"
                            class="h-8 w-16 text-center"
                            :aria-label="`Action ${index + 1} uses (leave blank for unlimited)`"
                        />
                        <span class="text-muted-foreground">uses</span>
                        <select
                            v-model="form.actions[index].per"
                            class="h-8 rounded-md border border-input bg-background px-2 text-sm disabled:opacity-50"
                            :disabled="form.actions[index].uses === ''"
                            :aria-label="`Action ${index + 1} limit period`"
                        >
                            <option v-for="period in limitPeriods" :key="period.value" :value="period.value">{{ period.label }}</option>
                        </select>
                        <span v-if="form.actions[index].uses === ''" class="text-xs text-muted-foreground">Leave blank for unlimited.</span>
                    </div>
                    <InputError :message="entryError(list, index)" />
                </div>
                <Button type="button" variant="outline" size="sm" @click="addEntry(list)">
                    <Plus />
                    {{ list === 'traits' ? 'Add trait' : 'Add action' }}
                </Button>
            </section>

            <div class="flex items-center justify-end gap-2">
                <Button variant="outline" as-child>
                    <Link :href="route('compendium.index', creature ? { creature: creature.id } : {})">Cancel</Link>
                </Button>
                <Button type="submit" :disabled="form.processing">{{ isEditing ? 'Save changes' : 'Create creature' }}</Button>
            </div>
        </form>
    </AppLayout>
</template>
