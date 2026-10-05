<script setup lang="ts">
// Player view in a second window on the same computer (e.g. dragged onto a TV). The tracker writes
// the filtered fight to browser storage on every change, and the storage event brings it here.
// No server involved, so it works for guests and for fights outside a campaign.
import PlayerCombatView from '@/components/PlayerCombatView.vue';
import { playerViewStorageKey, readPlayerView } from '@/lib/playerView';
import type { SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const key = playerViewStorageKey(usePage<SharedData>().props.auth.user?.id ?? null);
const fight = ref(readPlayerView(key));

const onStorage = (event: StorageEvent) => {
    if (event.key === key) fight.value = readPlayerView(key);
};
onMounted(() => window.addEventListener('storage', onStorage));
onBeforeUnmount(() => window.removeEventListener('storage', onStorage));
</script>

<template>
    <Head title="Player view" />

    <div class="min-h-screen bg-background p-4 sm:p-8">
        <div class="mx-auto max-w-5xl">
            <PlayerCombatView :fight="fight" large empty-text="Nothing to show yet. Start combat in the tracker and the fight appears here." />
        </div>
    </div>
</template>
