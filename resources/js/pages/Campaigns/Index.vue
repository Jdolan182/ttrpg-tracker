<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Castle, Plus, Swords, UsersRound } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineProps<{
    running: { id: number; name: string; description: string | null; players: number; party: number; encounters: number }[];
    playing: { id: number; name: string; description: string | null; dm: string; character: string | null }[];
}>();

const page = usePage<SharedData>();
const campaignLimit = computed(() => page.props.limits?.campaigns);
const joinedLimit = computed(() => page.props.limits?.campaigns_joined);
const atLimit = computed(() => !!campaignLimit.value && campaignLimit.value.used >= campaignLimit.value.limit);

const creating = ref(false);
const form = useForm({ name: '', description: '' });
const create = () => form.post(route('campaigns.store'));

const textareaClass =
    'flex min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2';
</script>

<template>
    <Head title="Campaigns" />

    <AppLayout>
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-semibold tracking-tight">Campaigns</h1>
                <span
                    v-if="campaignLimit"
                    class="ml-auto text-xs tabular-nums"
                    :class="atLimit ? 'font-medium text-amber-700 dark:text-amber-400' : 'text-muted-foreground'"
                >
                    {{ campaignLimit.used }} of {{ campaignLimit.limit }} campaigns
                </span>
                <Button size="sm" :class="campaignLimit ? '' : 'ml-auto'" :disabled="atLimit" @click="creating = !creating">
                    <Plus />
                    New campaign
                </Button>
            </div>

            <form v-if="creating" class="space-y-4 rounded-xl border border-border bg-card p-4 shadow-sm" @submit.prevent="create">
                <div class="grid gap-1.5">
                    <Label for="campaign-name">Name</Label>
                    <Input id="campaign-name" v-model="form.name" required maxlength="100" placeholder="Curse of the Crimson Keep" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="campaign-description">Description</Label>
                    <textarea
                        id="campaign-description"
                        v-model="form.description"
                        maxlength="5000"
                        :class="textareaClass"
                        placeholder="What your players should know going in."
                    />
                    <InputError :message="form.errors.description" />
                </div>
                <InputError :message="form.errors.limit" />
                <div class="flex justify-end gap-2">
                    <Button type="button" variant="outline" @click="creating = false">Cancel</Button>
                    <Button type="submit" :disabled="form.processing">Create campaign</Button>
                </div>
            </form>

            <p v-if="atLimit" class="text-sm text-muted-foreground">
                You've reached the limit of {{ campaignLimit?.limit }} campaigns. Delete one to start another.
            </p>

            <!-- Campaigns you run -->
            <section class="space-y-3">
                <h2 class="text-lg font-semibold">You're the DM</h2>
                <div v-if="running.length" class="grid gap-3 sm:grid-cols-2">
                    <Link
                        v-for="campaign in running"
                        :key="campaign.id"
                        :href="route('campaigns.show', campaign.id)"
                        class="group rounded-xl border border-border bg-card p-4 shadow-sm transition-colors hover:border-primary/40"
                    >
                        <p class="font-display text-lg font-semibold group-hover:text-primary">{{ campaign.name }}</p>
                        <p v-if="campaign.description" class="mt-1 line-clamp-2 text-sm text-muted-foreground">{{ campaign.description }}</p>
                        <p class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            <span class="inline-flex items-center gap-1"><Castle class="size-3.5" /> {{ campaign.party }} in the party</span>
                            <span class="inline-flex items-center gap-1"><UsersRound class="size-3.5" /> {{ campaign.players }} players joined</span>
                            <span class="inline-flex items-center gap-1"><Swords class="size-3.5" /> {{ campaign.encounters }} encounters</span>
                        </p>
                    </Link>
                </div>
                <div v-else class="rounded-xl border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground">
                    Start a campaign to keep your party and encounters together, and invite your players to follow the fights.
                </div>
            </section>

            <!-- Campaigns you play in -->
            <section class="space-y-3">
                <div class="flex items-baseline gap-3">
                    <h2 class="text-lg font-semibold">You're playing</h2>
                    <span v-if="joinedLimit && playing.length" class="text-xs tabular-nums text-muted-foreground">
                        {{ joinedLimit.used }} of {{ joinedLimit.limit }} joined
                    </span>
                </div>
                <div v-if="playing.length" class="grid gap-3 sm:grid-cols-2">
                    <Link
                        v-for="campaign in playing"
                        :key="campaign.id"
                        :href="route('campaigns.show', campaign.id)"
                        class="group rounded-xl border border-border bg-card p-4 shadow-sm transition-colors hover:border-primary/40"
                    >
                        <p class="font-display text-lg font-semibold group-hover:text-primary">{{ campaign.name }}</p>
                        <p class="text-xs text-muted-foreground">Run by {{ campaign.dm }}</p>
                        <p class="mt-2 text-sm">
                            <template v-if="campaign.character">
                                You're playing <span class="font-medium">{{ campaign.character }}</span>
                            </template>
                            <span v-else class="text-amber-700 dark:text-amber-400">Pick your character</span>
                        </p>
                    </Link>
                </div>
                <p v-else class="text-sm text-muted-foreground">When a DM sends you an invite link, the campaign shows up here.</p>
            </section>
        </div>
    </AppLayout>
</template>
