<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import PlayerCombatView from '@/components/PlayerCombatView.vue';
import StatBlock from '@/components/StatBlock.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCampaignCombat } from '@/composables/useCampaignCombat';
import { confirmAction } from '@/composables/useConfirm';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import type { Creature, EnemyHpDisplay, PlayerViewFight } from '@/types/tracker';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    ChevronDown,
    Copy,
    LogOut,
    Maximize2,
    Pencil,
    Plus,
    RefreshCw,
    Square,
    Swords,
    Trash2,
    UserPlus,
    X,
} from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    campaign: {
        id: number;
        name: string;
        description: string | null;
        enemyHp: EnemyHpDisplay;
        dm: string;
        isDm: boolean;
        // Only for the DM.
        inviteUrl: string | null;
        playerLimit: number;
    };
    party: (Creature & { claimedBy: string | null })[];
    players: { id: number; name: string; characterId: number | null }[];
    myCharacterId: number | null;
    encounters: { id: number; name: string; round: number; updatedAt: string }[];
    availableEncounters: { id: number; name: string }[];
    availableCharacters: { id: number; name: string; campaign_id: number | null }[];
    combat: PlayerViewFight | null;
}>();

// --- Combat ---
const { fight, refresh: refreshFight } = useCampaignCombat(props.campaign.id, props.combat);
const endCombat = async () => {
    const ok = await confirmAction({
        title: 'Stop showing this fight?',
        message: 'Your players\' screens go back to "No fight right now". The encounter itself isn\'t changed.',
        confirmLabel: 'Stop showing',
        icon: Square,
    });
    if (!ok) return;
    router.delete(route('campaigns.combat.destroy', props.campaign.id), { preserveScroll: true, onSuccess: refreshFight });
};

const enemyHpOptions: { value: EnemyHpDisplay; label: string; hint: string }[] = [
    { value: 'bands', label: 'Bands', hint: 'Healthy, Bloodied or Down' },
    { value: 'exact', label: 'Exact HP', hint: 'The real numbers' },
    { value: 'hidden', label: 'Hidden', hint: "Players can't tell how hurt enemies are" },
];

// --- Details (DM) ---
const editing = ref(false);
const details = useForm({ name: props.campaign.name, description: props.campaign.description ?? '', enemy_hp: props.campaign.enemyHp });
const saveDetails = () =>
    details.put(route('campaigns.update', props.campaign.id), {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
        },
    });
const deleteCampaign = async () => {
    const ok = await confirmAction({
        title: `Delete "${props.campaign.name}"?`,
        message: 'Players lose access to it. Its characters and encounters are kept, just no longer in a campaign.',
        confirmLabel: 'Delete campaign',
        destructive: true,
        icon: Trash2,
    });
    if (!ok) return;
    router.delete(route('campaigns.destroy', props.campaign.id));
};

// --- Party ---
const openCharacter = ref<number | null>(null);
const toggleCharacter = (id: number) => {
    openCharacter.value = openCharacter.value === id ? null : id;
};
const characterToAdd = ref('');
const addCharacter = () => {
    if (!characterToAdd.value) return;
    router.post(route('campaigns.party.store', props.campaign.id), { creature_id: Number(characterToAdd.value) }, { preserveScroll: true });
    characterToAdd.value = '';
};
const removeCharacter = async (character: Creature & { claimedBy: string | null }) => {
    const note = character.claimedBy ? ` ${character.claimedBy} will need to pick another character.` : '';
    const ok = await confirmAction({
        title: `Take ${character.name} out of the party?`,
        message: `They stay in your compendium.${note}`,
        confirmLabel: 'Take out',
    });
    if (!ok) return;
    router.delete(route('campaigns.party.destroy', [props.campaign.id, character.id]), { preserveScroll: true });
};

// --- Claiming (players) ---
const claimError = ref('');
const claim = (characterId: number | null) =>
    router.put(
        route('campaigns.character.claim', props.campaign.id),
        { character_id: characterId },
        {
            preserveScroll: true,
            onError: (errors) => {
                claimError.value = Object.values(errors)[0] ?? '';
            },
            onSuccess: () => {
                claimError.value = '';
            },
        },
    );

// --- Encounters (DM) ---
const encounterToAdd = ref('');
const addEncounter = () => {
    if (!encounterToAdd.value) return;
    router.post(route('campaigns.encounters.store', props.campaign.id), { encounter_id: Number(encounterToAdd.value) }, { preserveScroll: true });
    encounterToAdd.value = '';
};
const removeEncounter = async (encounter: { id: number; name: string }) => {
    const ok = await confirmAction({ title: `Take "${encounter.name}" out of this campaign?`, message: 'It stays saved.', confirmLabel: 'Take out' });
    if (!ok) return;
    router.delete(route('campaigns.encounters.destroy', [props.campaign.id, encounter.id]), { preserveScroll: true });
};

// --- Players ---
const copied = ref(false);
const copyInvite = async () => {
    if (!props.campaign.inviteUrl) return;
    try {
        await navigator.clipboard.writeText(props.campaign.inviteUrl);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        window.prompt('Copy this invite link:', props.campaign.inviteUrl);
    }
};
const resetInvite = async () => {
    const ok = await confirmAction({
        title: 'Make a new invite link?',
        message: 'The current link stops working. Players already in the campaign stay.',
        confirmLabel: 'New link',
        icon: RefreshCw,
    });
    if (!ok) return;
    router.post(route('campaigns.invite.reset', props.campaign.id), {}, { preserveScroll: true });
};
const removePlayer = async (player: { id: number; name: string }) => {
    const ok = await confirmAction({
        title: `Remove ${player.name} from the campaign?`,
        message: 'They can rejoin with the invite link, unless you make a new one.',
        confirmLabel: 'Remove',
        destructive: true,
    });
    if (!ok) return;
    router.delete(route('campaigns.players.destroy', [props.campaign.id, player.id]), { preserveScroll: true });
};
const leave = async (myId: number) => {
    const ok = await confirmAction({
        title: `Leave "${props.campaign.name}"?`,
        message: 'You can rejoin with the invite link.',
        confirmLabel: 'Leave',
        destructive: true,
        icon: LogOut,
    });
    if (!ok) return;
    router.delete(route('campaigns.players.destroy', [props.campaign.id, myId]));
};
const myId = usePage<SharedData>().props.auth.user?.id;

const characterName = (id: number | null) => props.party.find((c) => c.id === id)?.name;
const roundLabel = (round: number) => (round === 0 ? 'Setting up' : `Round ${round}`);

const textareaClass =
    'flex min-h-24 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2';
const selectClass = 'h-9 min-w-0 flex-1 rounded-md border border-input bg-background px-2 text-sm';
</script>

<template>
    <Head :title="campaign.name" />

    <AppLayout>
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
            <!-- Header -->
            <div class="flex flex-wrap items-start gap-3">
                <Button variant="ghost" size="icon" as-child aria-label="Back to campaigns">
                    <Link :href="route('campaigns.index')"><ArrowLeft /></Link>
                </Button>
                <div class="min-w-0 flex-1">
                    <h1 class="text-2xl font-semibold tracking-tight">{{ campaign.name }}</h1>
                    <p class="text-sm text-muted-foreground">{{ campaign.isDm ? "You're the DM" : `Run by ${campaign.dm}` }}</p>
                </div>
                <Button v-if="campaign.isDm && !editing" variant="outline" size="sm" @click="editing = true">
                    <Pencil />
                    Edit details
                </Button>
            </div>

            <!-- Details: edit (DM) or read -->
            <form v-if="editing" class="space-y-4 rounded-xl border border-border bg-card p-4 shadow-sm" @submit.prevent="saveDetails">
                <div class="grid gap-1.5">
                    <Label for="campaign-name">Name</Label>
                    <Input id="campaign-name" v-model="details.name" required maxlength="100" />
                    <InputError :message="details.errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="campaign-description">Description</Label>
                    <textarea id="campaign-description" v-model="details.description" maxlength="5000" :class="textareaClass" />
                    <InputError :message="details.errors.description" />
                </div>
                <fieldset class="space-y-1.5">
                    <legend class="text-sm font-medium">What players see of enemy HP</legend>
                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="option in enemyHpOptions"
                            :key="option.value"
                            class="flex cursor-pointer items-start gap-2 rounded-md border px-3 py-2 text-sm"
                            :class="details.enemy_hp === option.value ? 'border-primary ring-1 ring-primary' : 'border-border'"
                        >
                            <input v-model="details.enemy_hp" type="radio" name="enemy_hp" :value="option.value" class="mt-1" />
                            <span>
                                <span class="block font-medium">{{ option.label }}</span>
                                <span class="block text-xs text-muted-foreground">{{ option.hint }}</span>
                            </span>
                        </label>
                    </div>
                </fieldset>
                <div class="flex flex-wrap items-center gap-2">
                    <Button type="button" variant="ghost" size="sm" class="text-red-600 dark:text-red-400" @click="deleteCampaign">
                        <Trash2 />
                        Delete campaign
                    </Button>
                    <Button type="button" variant="outline" class="ml-auto" @click="editing = false">Cancel</Button>
                    <Button type="submit" :disabled="details.processing">Save</Button>
                </div>
            </form>
            <section v-else-if="campaign.description" class="rounded-xl border border-border bg-card p-4 shadow-sm">
                <p class="whitespace-pre-line text-sm">{{ campaign.description }}</p>
            </section>

            <!-- The fight in progress, live, as players see it -->
            <section class="space-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold">Combat</h2>
                    <span v-if="fight" class="inline-flex items-center gap-1.5 text-xs font-medium text-primary">
                        <span class="size-2 animate-pulse rounded-full bg-primary" aria-hidden="true" />
                        Live
                    </span>
                    <div class="ml-auto flex flex-wrap gap-2">
                        <Button v-if="campaign.isDm && fight" variant="outline" size="sm" @click="endCombat">
                            <Square />
                            Stop showing
                        </Button>
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="route('campaigns.combat', campaign.id)">
                                <Maximize2 />
                                Full screen
                            </Link>
                        </Button>
                    </div>
                </div>
                <PlayerCombatView
                    :fight="fight"
                    :empty-text="
                        campaign.isDm
                            ? 'Start combat on an encounter in this campaign and your players see it here, live. Hidden combatants stay hidden.'
                            : `No fight right now. When ${campaign.dm} starts combat, you can follow it here.`
                    "
                />
            </section>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                <!-- Party -->
                <section class="space-y-3">
                    <div class="flex items-center gap-3">
                        <h2 class="text-lg font-semibold">The party</h2>
                        <Button v-if="campaign.isDm" size="sm" variant="outline" class="ml-auto" as-child>
                            <Link :href="route('creatures.create', { campaign: campaign.id })">
                                <Plus />
                                New character
                            </Link>
                        </Button>
                    </div>

                    <p v-if="!campaign.isDm && !myCharacterId && party.length" class="text-sm text-amber-700 dark:text-amber-400">
                        Pick the character you're playing.
                    </p>
                    <InputError :message="claimError" />

                    <div v-if="party.length" class="space-y-2">
                        <div v-for="character in party" :key="character.id" class="rounded-xl border border-border bg-card shadow-sm">
                            <div class="flex flex-wrap items-center gap-3 p-3">
                                <button
                                    type="button"
                                    class="flex min-w-0 flex-1 items-center gap-2 text-left"
                                    :aria-expanded="openCharacter === character.id"
                                    @click="toggleCharacter(character.id)"
                                >
                                    <ChevronDown
                                        class="size-4 shrink-0 text-muted-foreground transition-transform"
                                        :class="openCharacter === character.id ? 'rotate-180' : ''"
                                    />
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium">{{ character.name }}</span>
                                        <span class="block truncate text-xs text-muted-foreground">
                                            {{ [character.summary, character.rating].filter(Boolean).join(' · ') }}
                                        </span>
                                    </span>
                                </button>
                                <span class="text-xs" :class="character.claimedBy ? 'text-foreground' : 'text-muted-foreground'">
                                    {{ character.claimedBy ? `Played by ${character.claimedBy}` : 'Not claimed yet' }}
                                </span>
                                <!-- Players: claim an unclaimed character, or let go of your own -->
                                <template v-if="!campaign.isDm">
                                    <Button v-if="myCharacterId === character.id" size="sm" variant="outline" @click="claim(null)">
                                        <X />
                                        Not mine
                                    </Button>
                                    <Button v-else-if="!character.claimedBy" size="sm" @click="claim(character.id)">
                                        <Check />
                                        This is me
                                    </Button>
                                </template>
                                <Button
                                    v-else
                                    variant="ghost"
                                    size="icon"
                                    class="h-8 w-8 text-muted-foreground hover:text-red-600"
                                    :aria-label="`Take ${character.name} out of the party`"
                                    @click="removeCharacter(character)"
                                >
                                    <X />
                                </Button>
                            </div>
                            <div v-if="openCharacter === character.id" class="border-t border-border p-4">
                                <StatBlock :creature="character" />
                            </div>
                        </div>
                    </div>
                    <p v-else class="rounded-xl border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                        {{
                            campaign.isDm
                                ? 'Add the player characters in this campaign so your players can claim them.'
                                : "The DM hasn't added any characters yet."
                        }}
                    </p>

                    <div v-if="campaign.isDm && availableCharacters.length" class="flex gap-2">
                        <select v-model="characterToAdd" :class="selectClass" aria-label="Add one of your player characters">
                            <option value="">Add one of your player characters…</option>
                            <option v-for="c in availableCharacters" :key="c.id" :value="String(c.id)">
                                {{ c.name }}{{ c.campaign_id ? ' (moves from another campaign)' : '' }}
                            </option>
                        </select>
                        <Button size="sm" variant="outline" class="h-9" :disabled="!characterToAdd" @click="addCharacter">Add</Button>
                    </div>
                </section>

                <div class="space-y-6">
                    <!-- Encounters (DM) -->
                    <section v-if="campaign.isDm" class="space-y-3">
                        <div class="flex items-center gap-3">
                            <h2 class="text-lg font-semibold">Encounters</h2>
                            <Button size="sm" variant="outline" class="ml-auto" as-child>
                                <a :href="route('encounters.index', { new_in_campaign: campaign.id })">
                                    <Plus />
                                    New encounter
                                </a>
                            </Button>
                        </div>
                        <div v-if="encounters.length" class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                            <div
                                v-for="encounter in encounters"
                                :key="encounter.id"
                                class="flex items-center gap-3 border-b border-border px-3 py-2.5 text-sm last:border-b-0"
                            >
                                <Swords class="size-4 shrink-0 text-muted-foreground" />
                                <a
                                    :href="route('encounters.index', { encounter: encounter.id })"
                                    class="min-w-0 flex-1 truncate font-medium hover:text-primary"
                                >
                                    {{ encounter.name }}
                                </a>
                                <span class="shrink-0 text-xs text-muted-foreground">{{ roundLabel(encounter.round) }}</span>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="h-7 w-7 text-muted-foreground hover:text-red-600"
                                    :aria-label="`Take ${encounter.name} out of this campaign`"
                                    @click="removeEncounter(encounter)"
                                >
                                    <X />
                                </Button>
                            </div>
                        </div>
                        <p v-else class="rounded-xl border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                            No encounters yet. Start a new one, or add one you've already saved.
                        </p>
                        <div v-if="availableEncounters.length" class="flex gap-2">
                            <select v-model="encounterToAdd" :class="selectClass" aria-label="Add a saved encounter">
                                <option value="">Add a saved encounter…</option>
                                <option v-for="e in availableEncounters" :key="e.id" :value="String(e.id)">{{ e.name }}</option>
                            </select>
                            <Button size="sm" variant="outline" class="h-9" :disabled="!encounterToAdd" @click="addEncounter">Add</Button>
                        </div>
                    </section>

                    <!-- Players -->
                    <section class="space-y-3">
                        <div class="flex items-baseline gap-3">
                            <h2 class="text-lg font-semibold">Players</h2>
                            <span v-if="campaign.isDm" class="text-xs tabular-nums text-muted-foreground">
                                {{ players.length }} of {{ campaign.playerLimit }}
                            </span>
                        </div>

                        <div v-if="campaign.isDm && campaign.inviteUrl" class="space-y-2 rounded-xl border border-border bg-card p-3 shadow-sm">
                            <p class="inline-flex items-center gap-1.5 text-sm font-medium">
                                <UserPlus class="size-4" />
                                Invite link
                            </p>
                            <div class="flex gap-2">
                                <Input
                                    :model-value="campaign.inviteUrl"
                                    readonly
                                    class="h-9 text-xs"
                                    aria-label="Invite link"
                                    @focus="($event.target as HTMLInputElement).select()"
                                />
                                <Button size="sm" class="h-9" @click="copyInvite">
                                    <Check v-if="copied" />
                                    <Copy v-else />
                                    {{ copied ? 'Copied' : 'Copy' }}
                                </Button>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-xs text-muted-foreground">Anyone with the link can join while there's room.</p>
                                <Button variant="ghost" size="sm" class="h-7 px-2 text-xs" @click="resetInvite">
                                    <RefreshCw />
                                    New link
                                </Button>
                            </div>
                        </div>

                        <ul v-if="players.length" class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                            <li
                                v-for="player in players"
                                :key="player.id"
                                class="flex items-center gap-3 border-b border-border px-3 py-2.5 text-sm last:border-b-0"
                            >
                                <span class="min-w-0 flex-1 truncate font-medium">{{ player.name }}</span>
                                <span class="shrink-0 text-xs text-muted-foreground">{{
                                    characterName(player.characterId) ?? 'No character yet'
                                }}</span>
                                <Button
                                    v-if="campaign.isDm"
                                    variant="ghost"
                                    size="icon"
                                    class="h-7 w-7 text-muted-foreground hover:text-red-600"
                                    :aria-label="`Remove ${player.name}`"
                                    @click="removePlayer(player)"
                                >
                                    <X />
                                </Button>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-muted-foreground">
                            {{ campaign.isDm ? 'Nobody has joined yet. Send your players the invite link.' : 'No other players yet.' }}
                        </p>

                        <Button v-if="!campaign.isDm && myId" variant="ghost" size="sm" class="text-red-600 dark:text-red-400" @click="leave(myId)">
                            <LogOut />
                            Leave campaign
                        </Button>
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
