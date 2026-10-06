import { sendJson } from '@/lib/http';
import { HISTORY_LIMIT } from '@/lib/playerView';
import { computed, onBeforeUnmount, ref, watch, type Ref } from 'vue';
import type { FightState } from './types';

// Campaign fights also go to the server as they change, so players follow along on their own
// devices. Separate from saving: the saved encounter only changes on Save. Debounced, since a
// single click can change several things.
const LIVE_DEBOUNCE_MS = 400;

/** Keeps a campaign's player view in step with the fight while it's in combat. */
export function useLiveSync(fight: FightState & { name: Ref<string>; campaignId: Ref<number | null> }, isGuest: boolean) {
    const { name, campaignId, round, activeIndex, combatants, log } = fight;
    const liveStatus = ref<'off' | 'live' | 'error'>('off');
    let liveTimer: ReturnType<typeof setTimeout> | undefined;
    // What was last sent, so unchanged state (e.g. just opening the page) isn't sent again.
    let lastLive = '';

    const livePayload = () =>
        JSON.stringify({
            campaignId: campaignId.value,
            name: name.value.trim() || 'Untitled encounter',
            round: round.value,
            activeIndex: activeIndex.value,
            combatants: combatants.value,
            // Players only get the latest part of the history, so there's no need to send it all.
            log: log.value.slice(-HISTORY_LIMIT),
        });

    const pushLive = async () => {
        liveTimer = undefined;
        const id = campaignId.value;
        if (!id || round.value < 1) return;
        const payload = livePayload();
        lastLive = payload;
        try {
            const response = await sendJson('PUT', route('campaigns.combat.update', id), JSON.parse(payload));
            liveStatus.value = response.ok ? 'live' : 'error';
        } catch {
            liveStatus.value = 'error';
        }
    };

    /** End combat: players stop seeing the fight straight away. */
    const endLive = (id = campaignId.value) => {
        clearTimeout(liveTimer);
        liveTimer = undefined;
        lastLive = '';
        liveStatus.value = 'off';
        if (!id || isGuest) return;
        sendJson('DELETE', route('campaigns.combat.destroy', id)).catch(() => {
            // Offline: the DM can stop it from the campaign page.
        });
    };

    // The campaign picker. Moving a fight in progress to another campaign (or none) takes it off the old
    // campaign's screens; the watch below then sends it to the new one. Opening a different encounter
    // doesn't go through here, so the previous campaign keeps showing its fight until it's ended.
    const pickedCampaign = computed({
        get: () => campaignId.value,
        set: (id: number | null) => {
            const previous = campaignId.value;
            if (previous === id) return;
            if (previous && round.value > 0) endLive(previous);
            campaignId.value = id;
        },
    });

    // Only changes during combat are sent.
    watch(
        [campaignId, name, round, activeIndex, combatants, log],
        () => {
            if (isGuest || !campaignId.value || round.value < 1) {
                if (!campaignId.value) liveStatus.value = 'off';
                return;
            }
            if (livePayload() === lastLive) return;
            clearTimeout(liveTimer);
            liveTimer = setTimeout(pushLive, LIVE_DEBOUNCE_MS);
        },
        { deep: true },
    );

    // Leaving the page mid-debounce: send the last change now rather than lose it.
    onBeforeUnmount(() => {
        if (liveTimer === undefined) return;
        clearTimeout(liveTimer);
        pushLive();
    });

    /** Opening the page isn't a change: players keep whatever they were shown until the DM does something. */
    const markLiveBaseline = () => {
        lastLive = livePayload();
    };

    return { liveStatus, endLive, pickedCampaign, markLiveBaseline };
}
