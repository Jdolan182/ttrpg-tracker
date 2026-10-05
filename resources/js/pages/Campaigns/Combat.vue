<script setup lang="ts">
// Full-screen player view of a campaign's fight, e.g. on a TV by the table. Follows the DM live.
import PlayerCombatView from '@/components/PlayerCombatView.vue';
import { useCampaignCombat } from '@/composables/useCampaignCombat';
import type { PlayerViewFight } from '@/types/tracker';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';

const props = defineProps<{
    campaign: { id: number; name: string; isDm: boolean };
    combat: PlayerViewFight | null;
}>();

const { fight } = useCampaignCombat(props.campaign.id, props.combat);
</script>

<template>
    <Head :title="`${campaign.name}: combat`" />

    <div class="min-h-screen bg-background p-4 sm:p-8">
        <div class="mx-auto max-w-5xl space-y-6">
            <div class="flex items-center gap-3">
                <Link
                    :href="route('campaigns.show', campaign.id)"
                    class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft class="size-4" />
                    {{ campaign.name }}
                </Link>
            </div>
            <PlayerCombatView :fight="fight" large />
        </div>
    </div>
</template>
