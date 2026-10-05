import type { PlayerViewFight } from '@/types/tracker';
import { useConnectionStatus, useEcho } from '@laravel/echo-vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

// How often to check anyway: rarely while the live connection works, often while it doesn't
// (e.g. when shared through a tunnel that only reaches the web server, not Reverb).
const CONNECTED_REFRESH_MS = 60_000;
const FALLBACK_REFRESH_MS = 5_000;

/**
 * A campaign's fight as players see it, kept up to date. The server only broadcasts that something
 * changed; the fight itself is fetched over HTTP, already filtered for players.
 */
export function useCampaignCombat(campaignId: number, initial: PlayerViewFight | null) {
    const fight = ref<PlayerViewFight | null>(initial);
    const status = useConnectionStatus();
    let lastRefresh = Date.now();
    let inFlight = false;

    const refresh = async () => {
        if (inFlight) return;
        inFlight = true;
        lastRefresh = Date.now();
        try {
            const response = await fetch(route('campaigns.combat', campaignId), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (response.ok) fight.value = (await response.json()).combat;
        } catch {
            // Offline for a moment: the next ping or poll catches up.
        } finally {
            inFlight = false;
        }
    };

    useEcho(`campaign.${campaignId}`, '.combat.changed', refresh);

    // Pings sent while the connection was down (a background tab, a phone waking up, Reverb
    // restarting) are lost, so catch up whenever it comes back.
    watch(status, (now, before) => {
        if (now === 'connected' && before !== 'connected') refresh();
    });

    let timer: ReturnType<typeof setInterval> | undefined;
    const tick = () => {
        const wait = status.value === 'connected' ? CONNECTED_REFRESH_MS : FALLBACK_REFRESH_MS;
        if (document.visibilityState === 'visible' && Date.now() - lastRefresh >= wait) refresh();
    };
    // A phone that slept or a background tab may have missed pings.
    const onVisible = () => {
        if (document.visibilityState === 'visible') refresh();
    };

    onMounted(() => {
        timer = setInterval(tick, 1_000);
        document.addEventListener('visibilitychange', onVisible);
    });
    onBeforeUnmount(() => {
        clearInterval(timer);
        document.removeEventListener('visibilitychange', onVisible);
    });

    return { fight, status, refresh };
}
