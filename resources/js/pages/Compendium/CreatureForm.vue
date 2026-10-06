<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useStatDisplay } from '@/composables/useStatDisplay';
import AppLayout from '@/layouts/AppLayout.vue';
import { limitKind, limitPeriods, type LimitKind } from '@/lib/encounter';
import { formatModifier, modifier } from '@/lib/stats';
import { plainCopy } from '@/lib/utils';
import type { SharedData } from '@/types';
import type { Creature, CreatureAction, CreatureEntry, CreatureKind, CreatureStat, LimitPeriod } from '@/types/tracker';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Plus, X } from 'lucide-vue-next';
import { computed, watch } from 'vue';

// An action as edited: every kind of limit keeps its fields, so switching kinds and back doesn't lose
// them, and submit() sends only the chosen one.
type ActionForm = CreatureEntry & {
    limit: LimitKind | 'none';
    uses: number | string;
    per: LimitPeriod;
    rechargeDie: number | string;
    rechargeMin: number | string;
    cooldown: string;
    resource: string;
    cost: number | string;
};

type ResourceForm = {
    name: string;
    max: number | string;
    per: LimitPeriod;
};

// D&D's Recharge 5–6 and a short cooldown are the usual starting points.
const actionForm = (action?: CreatureAction): ActionForm => ({
    name: action?.name ?? '',
    description: action?.description ?? '',
    limit: (action && limitKind(action)) ?? 'none',
    uses: action?.uses ?? 1,
    per: action?.per ?? 'day',
    rechargeDie: action?.recharge?.die ?? 6,
    rechargeMin: action?.recharge?.min ?? 5,
    cooldown: action?.cooldown ?? '1d4',
    resource: action?.resource ?? '',
    cost: action?.cost ?? 1,
});

const limitOptions: { value: ActionForm['limit']; label: string }[] = [
    { value: 'none', label: 'No limit' },
    { value: 'uses', label: 'Limited uses' },
    { value: 'recharge', label: 'Recharges on a roll' },
    { value: 'cooldown', label: 'Cooldown' },
    { value: 'resource', label: 'Costs a resource' },
];

const props = defineProps<{
    // The creature being edited, or null when creating.
    creature: Creature | null;
    // A creature to copy from when duplicating.
    template: Creature | null;
    // Making a player character for this campaign's party (from the campaign page).
    campaign?: { id: number; name: string } | null;
}>();

const isEditing = props.creature !== null;
const forCampaign = !isEditing && props.campaign ? props.campaign : null;
// Where Back and Cancel go: the campaign it was for, the creature being edited, or the compendium.
const backHref = forCampaign
    ? route('campaigns.show', forCampaign.id)
    : route('compendium.index', props.creature ? { creature: props.creature.id } : {});
const heading = isEditing ? `Edit ${props.creature?.name}` : forCampaign ? `New character for ${forCampaign.name}` : 'New creature';

// Warn up front rather than after filling the whole form in; the server enforces it either way.
const page = usePage<SharedData>();
const atLimit = computed(() => {
    const limit = page.props.limits?.creatures;
    return !isEditing && !!limit && limit.used >= limit.limit;
});
const base = props.creature ?? props.template;
// The server's limit check isn't a form field, so its error needs looking up by name.
const limitError = computed(() => (form.errors as Record<string, string | undefined>).limit);

const defaultStats: CreatureStat[] = ['STR', 'DEX', 'CON', 'INT', 'WIS', 'CHA'].map((label) => ({ label, value: 10 }));

const form = useForm({
    kind: (forCampaign ? 'player' : (base?.kind ?? 'monster')) as CreatureKind,
    name: props.template ? `${props.template.name} (copy)` : (base?.name ?? ''),
    summary: base?.summary ?? '',
    rating: base?.rating ?? '',
    hp: base?.hp ?? 10,
    ac: base?.ac ?? 10,
    // Blank: use DEX (sent as null).
    initiativeBonus: (base?.initiativeBonus ?? '') as number | string,
    speed: base?.speed ?? '30 ft.',
    stats: plainCopy(base?.stats ?? defaultStats),
    traits: plainCopy(base?.traits ?? []) as CreatureEntry[],
    actions: plainCopy(base?.actions ?? []).map(actionForm),
    resources: plainCopy(base?.resources ?? []) as ResourceForm[],
});

// Renaming a resource takes the actions that spend it along.
watch(
    () => form.resources.map((r) => r.name),
    (names, before) => {
        if (names.length !== before.length) return;
        names.forEach((name, i) => {
            if (name === before[i]) return;
            for (const action of form.actions) if (action.resource === before[i]) action.resource = name;
        });
    },
);

const addEntry = (list: 'traits' | 'actions') => {
    if (list === 'actions') form.actions.push(actionForm());
    else form.traits.push({ name: '', description: '' });
};

const actionFields = ['uses', 'per', 'recharge', 'recharge.die', 'recharge.min', 'cooldown', 'resource', 'cost'];
const entryError = (list: 'traits' | 'actions', index: number) =>
    listError(list, index, 'name') ??
    listError(list, index, 'description') ??
    (list === 'actions' ? actionFields.map((field) => listError(list, index, field)).find(Boolean) : undefined);

const kindOptions: { value: CreatureKind; label: string }[] = [
    { value: 'monster', label: 'Monster' },
    { value: 'npc', label: 'NPC' },
    { value: 'player', label: 'Player character' },
];

const submit = () => {
    // Only the chosen kind of limit is sent; the rest go as null.
    const withLimits = form.transform((data) => ({
        ...data,
        campaign_id: forCampaign?.id ?? null,
        initiativeBonus: data.initiativeBonus === '' ? null : data.initiativeBonus,
        actions: data.actions.map(({ limit, ...action }) => ({
            name: action.name,
            description: action.description,
            uses: limit === 'uses' ? action.uses : null,
            per: limit === 'uses' ? action.per : null,
            recharge: limit === 'recharge' ? { die: action.rechargeDie, min: action.rechargeMin } : null,
            cooldown: limit === 'cooldown' ? action.cooldown.trim() : null,
            resource: limit === 'resource' ? action.resource : null,
            cost: limit === 'resource' ? action.cost : null,
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

const selectClass = 'h-8 rounded-md border border-input bg-background px-2 text-sm';
// A limit's words and inputs, kept on one line so they wrap together; the hint below gets its own line.
const phraseClass = 'inline-flex items-center gap-2 whitespace-nowrap';
const hintClass = 'basis-full text-xs text-muted-foreground sm:basis-auto';

const textareaClass =
    'flex min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2';
</script>

<template>
    <Head :title="heading" />

    <AppLayout>
        <form class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4" @submit.prevent="submit">
            <div class="flex flex-wrap items-center gap-3">
                <Button variant="ghost" size="icon" as-child :aria-label="forCampaign ? 'Back to the campaign' : 'Back to compendium'">
                    <Link :href="backHref">
                        <ArrowLeft />
                    </Link>
                </Button>
                <h1 class="text-2xl font-semibold tracking-tight">{{ heading }}</h1>
            </div>
            <p v-if="forCampaign" class="-mt-4 text-sm text-muted-foreground">
                They'll join the party, and your players can claim them. They're also kept in your compendium.
            </p>

            <p
                v-if="limitError || atLimit"
                class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/60 dark:text-amber-100"
                role="alert"
            >
                {{
                    limitError ??
                    `You've reached the limit of ${page.props.limits?.creatures.limit} creatures. Delete one from your compendium to make room.`
                }}
            </p>

            <!-- Basics -->
            <section class="grid gap-4 rounded-xl border border-border bg-card p-4 shadow-sm sm:grid-cols-2">
                <div class="grid gap-1.5 sm:col-span-2">
                    <Label for="name">Name</Label>
                    <Input id="name" v-model="form.name" required maxlength="100" placeholder="Goblin shaman" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="kind">Type</Label>
                    <p v-if="forCampaign" id="kind" class="flex h-10 items-center text-sm text-muted-foreground">Player character</p>
                    <select v-else id="kind" v-model="form.kind" class="h-10 rounded-md border border-input bg-background px-3 text-sm">
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
            <section class="grid gap-4 rounded-xl border border-border bg-card p-4 shadow-sm sm:grid-cols-4">
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
                    <Label for="initiative-bonus">Initiative bonus</Label>
                    <Input
                        id="initiative-bonus"
                        v-model="form.initiativeBonus"
                        type="number"
                        min="-100"
                        max="100"
                        placeholder="From DEX"
                        title="Added to the d20. Leave blank to use DEX, or a plain d20 if there's no DEX."
                    />
                    <InputError :message="form.errors.initiativeBonus" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="speed">Speed</Label>
                    <Input id="speed" v-model="form.speed" maxlength="100" placeholder="30 ft., fly 60 ft." />
                    <InputError :message="form.errors.speed" />
                </div>
            </section>

            <!-- Stats -->
            <section class="space-y-3 rounded-xl border border-border bg-card p-4 shadow-sm">
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

            <!-- Resources: pools its actions spend from -->
            <section class="space-y-3 rounded-xl border border-border bg-card p-4 shadow-sm">
                <div>
                    <h2 class="font-semibold">Resources</h2>
                    <p class="text-sm text-muted-foreground">
                        Points its actions spend, like legendary actions, spell slots, mana or focus. Optional.
                    </p>
                </div>
                <div v-for="(resource, index) in form.resources" :key="index" class="space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <Input
                            v-model="resource.name"
                            maxlength="50"
                            required
                            placeholder="Legendary actions"
                            class="min-w-40 flex-1"
                            :aria-label="`Resource ${index + 1} name`"
                        />
                        <Input
                            v-model="resource.max"
                            type="number"
                            min="1"
                            max="99"
                            required
                            class="w-20"
                            :aria-label="`Resource ${index + 1} amount`"
                        />
                        <select v-model="resource.per" :class="selectClass" :aria-label="`Resource ${index + 1} refills`">
                            <option v-for="period in limitPeriods" :key="period.value" :value="period.value">refills {{ period.label }}</option>
                        </select>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :aria-label="`Remove ${resource.name || 'resource'}`"
                            @click="form.resources.splice(index, 1)"
                        >
                            <X />
                        </Button>
                    </div>
                    <InputError
                        :message="
                            listError('resources', index, 'name') ?? listError('resources', index, 'max') ?? listError('resources', index, 'per')
                        "
                    />
                </div>
                <Button type="button" variant="outline" size="sm" @click="form.resources.push({ name: '', max: 3, per: 'turn' })">
                    <Plus />
                    Add resource
                </Button>
            </section>

            <!-- Traits and actions share the same name + description editor -->
            <section
                v-for="list in ['traits', 'actions'] as const"
                :key="list"
                class="space-y-3 rounded-xl border border-border bg-card p-4 shadow-sm"
            >
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
                    <!-- Optional limit on how often it can be used: one kind per action. On phones the kind
                         gets its own line, and each phrase ("back on 5 or more on a d6") wraps as a whole. -->
                    <div v-if="list === 'actions'" class="flex flex-wrap items-center gap-2 text-sm">
                        <select
                            v-model="form.actions[index].limit"
                            :class="[selectClass, 'w-full sm:w-auto']"
                            :aria-label="`Action ${index + 1} limit`"
                        >
                            <option
                                v-for="option in limitOptions"
                                :key="option.value"
                                :value="option.value"
                                :disabled="option.value === 'resource' && !form.resources.length"
                            >
                                {{ option.label }}{{ option.value === 'resource' && !form.resources.length ? ' (add a resource first)' : '' }}
                            </option>
                        </select>
                        <span v-if="form.actions[index].limit === 'uses'" :class="phraseClass">
                            <Input
                                v-model="form.actions[index].uses"
                                type="number"
                                min="1"
                                max="99"
                                class="h-8 w-16 text-center"
                                :aria-label="`Action ${index + 1} uses`"
                            />
                            <span class="text-muted-foreground">uses</span>
                            <select v-model="form.actions[index].per" :class="selectClass" :aria-label="`Action ${index + 1} limit period`">
                                <option v-for="period in limitPeriods" :key="period.value" :value="period.value">{{ period.label }}</option>
                            </select>
                        </span>
                        <template v-else-if="form.actions[index].limit === 'recharge'">
                            <span :class="phraseClass">
                                <span class="text-muted-foreground">back on</span>
                                <Input
                                    v-model="form.actions[index].rechargeMin"
                                    type="number"
                                    min="1"
                                    class="h-8 w-14 text-center"
                                    :aria-label="`Action ${index + 1} recharges on this or more`"
                                />
                                <span class="text-muted-foreground">or more on a d</span>
                                <Input
                                    v-model="form.actions[index].rechargeDie"
                                    type="number"
                                    min="2"
                                    max="100"
                                    class="h-8 w-14 text-center"
                                    :aria-label="`Action ${index + 1} recharge die`"
                                />
                            </span>
                            <span :class="hintClass">Rolled at the start of each of its turns once used.</span>
                        </template>
                        <template v-else-if="form.actions[index].limit === 'cooldown'">
                            <span :class="phraseClass">
                                <span class="text-muted-foreground">unusable for</span>
                                <Input
                                    v-model="form.actions[index].cooldown"
                                    maxlength="20"
                                    placeholder="1d4"
                                    class="h-8 w-20 text-center"
                                    :aria-label="`Action ${index + 1} cooldown, in rounds or dice`"
                                />
                                <span class="text-muted-foreground">rounds after use</span>
                            </span>
                            <span :class="hintClass">A number, or dice rolled when it's used.</span>
                        </template>
                        <span v-else-if="form.actions[index].limit === 'resource'" :class="phraseClass">
                            <span class="text-muted-foreground">costs</span>
                            <Input
                                v-model="form.actions[index].cost"
                                type="number"
                                min="1"
                                max="99"
                                class="h-8 w-16 text-center"
                                :aria-label="`Action ${index + 1} cost`"
                            />
                            <select
                                v-model="form.actions[index].resource"
                                :class="[selectClass, 'min-w-0 max-w-44']"
                                :aria-label="`Action ${index + 1} resource`"
                            >
                                <option value="" disabled>Choose a resource</option>
                                <option v-for="resource in form.resources" :key="resource.name" :value="resource.name">
                                    {{ resource.name || 'Unnamed resource' }}
                                </option>
                            </select>
                        </span>
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
                    <Link :href="backHref">Cancel</Link>
                </Button>
                <Button type="submit" :disabled="form.processing">{{
                    isEditing ? 'Save changes' : forCampaign ? 'Create character' : 'Create creature'
                }}</Button>
            </div>
        </form>
    </AppLayout>
</template>
