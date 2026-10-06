import { ask, confirmAction } from '@/composables/useConfirm';
import { downloadBackup, fightBackup } from '@/lib/backup';
import { normalizeCombatants } from '@/lib/encounter';
import { readTracker, trackerStorageKey, writeTracker } from '@/lib/trackerStorage';
import { fingerprint, plainCopy } from '@/lib/utils';
import type { SharedData } from '@/types';
import type { Creature, Encounter, EncounterSummary, TrackerCampaign } from '@/types/tracker';
import { router, usePage } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch, type ComputedRef, type Ref } from 'vue';
import type { FightState } from './types';

export interface TrackerProps {
    // The signed-in user's encounters by name, most recently updated first. Always empty for guests.
    savedEncounters: EncounterSummary[];
    // The one saved encounter sent in full: the one asked for (?encounter=), else the most recent.
    openEncounter: Encounter | null;
    // Everything the user can add: SRD creatures, plus their own when signed in.
    creatures: Creature[];
    // Campaigns the user runs, for the campaign picker and "Add party".
    campaigns: TrackerCampaign[];
    // Set when arriving from a campaign's "New encounter" button.
    newInCampaign: number | null;
}

interface EncounterRefs extends FightState {
    encounterId: Ref<number | null>;
    name: Ref<string>;
    campaignId: Ref<number | null>;
}

/**
 * The encounter around the fight: which one it is, saving and opening, the copy kept in browser
 * storage, unsaved-changes tracking, and following ?encounter= / ?new_in_campaign= links.
 */
export function useEncounterFile(
    props: TrackerProps,
    state: EncounterRefs,
    { creaturesById, onOpened }: { creaturesById: ComputedRef<Map<number, Creature>>; onOpened: () => void },
) {
    const { encounterId, name, campaignId, combatants, round, activeIndex, log } = state;
    const page = usePage<SharedData>();
    const user = page.props.auth.user;
    const isGuest = !user;
    // Saving needs a verified email; until then the account works like a guest's here.
    const canSave = !!user?.email_verified_at;

    const saving = ref(false);
    const saveError = ref('');
    const loadingEncounter = ref(false);

    // Unsaved-changes tracking: compare a fingerprint of the current fight with one taken when it was
    // last saved or opened. A fingerprint rather than a copy, so it can be kept in browser storage too.
    const currentFingerprint = () =>
        fingerprint(
            JSON.stringify({
                name: name.value,
                // Left out when empty (undefined), so fingerprints stored before campaigns existed still match.
                campaignId: campaignId.value ?? undefined,
                round: round.value,
                activeIndex: activeIndex.value,
                combatants: combatants.value,
                log: log.value,
            }),
        );
    const savedFingerprint = ref('');
    const isDirty = computed(() => currentFingerprint() !== savedFingerprint.value);
    const markSaved = () => {
        savedFingerprint.value = currentFingerprint();
    };

    // A saved encounter as the tracker would show it: a plain copy, brought up to date for older saves.
    const loadable = (encounter: Encounter): Encounter => {
        const copy = plainCopy(encounter);
        normalizeCombatants(copy.combatants, creaturesById.value);
        copy.log = Array.isArray(copy.log) ? copy.log : [];
        return copy;
    };

    const opened = () => {
        saveError.value = '';
        onOpened();
    };

    // Drops ?encounter= / ?new_in_campaign= once they've been acted on, so a later refresh doesn't
    // act on them again (e.g. reopening an encounter after the DM has moved on to a new one).
    // Only once Inertia has written this page into the browser history, which happens after setup;
    // changing the URL before that breaks its history handling.
    let pageInHistory = false;
    const clearUrlQuery = () => {
        if (!pageInHistory) return;
        // A client-side visit, so Inertia's own copy of the URL changes too and doesn't put the query back.
        if (window.location.search) router.replace({ url: route('encounters.index', undefined, false), preserveState: true, preserveScroll: true });
    };

    const startBlank = (inCampaign: number | null = null) => {
        clearUrlQuery();
        encounterId.value = null;
        name.value = 'Untitled encounter';
        campaignId.value = inCampaign;
        combatants.value = [];
        round.value = 0;
        activeIndex.value = 0;
        log.value = [];
        markSaved();
        opened();
    };

    const openSaved = (encounter: Encounter) => {
        const copy = loadable(encounter);
        encounterId.value = copy.id;
        name.value = copy.name;
        campaignId.value = copy.campaignId ?? null;
        combatants.value = copy.combatants;
        round.value = copy.round;
        activeIndex.value = copy.activeIndex < copy.combatants.length ? copy.activeIndex : 0;
        log.value = copy.log;
        markSaved();
        opened();
    };

    // Only one saved encounter comes with the page, so opening another fetches it (just that prop).
    const loadEncounter = (id: number) => {
        if (props.openEncounter?.id === id) {
            openSaved(props.openEncounter);
            return;
        }

        router.get(
            route('encounters.index'),
            { encounter: id },
            {
                only: ['openEncounter'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => {
                    loadingEncounter.value = true;
                },
                onSuccess: () => {
                    if (props.openEncounter?.id === id) openSaved(props.openEncounter);
                    else saveError.value = "Couldn't open that encounter. It may have been deleted.";
                },
                onFinish: () => {
                    loadingEncounter.value = false;
                },
            },
        );
    };

    /** Resolves to whether it saved, so "Save first" only carries on after a successful save. */
    const saveEncounter = (): Promise<boolean> =>
        new Promise((resolve) => {
            if (saving.value) return resolve(false);
            if (!canSave) {
                saveError.value = "Verify your email to save encounters: check your inbox for the link (there's a button above to send it again).";
                return resolve(false);
            }

            const payload = {
                name: name.value.trim() || 'Untitled encounter',
                campaignId: campaignId.value,
                round: round.value,
                activeIndex: activeIndex.value,
                combatants: combatants.value,
                log: log.value,
            };
            const options = {
                preserveScroll: true,
                preserveState: true,
                onStart: () => {
                    saving.value = true;
                    saveError.value = '';
                },
                onSuccess: () => {
                    encounterId.value = page.props.flash.savedEncounterId ?? encounterId.value;
                    name.value = payload.name;
                    markSaved();
                    resolve(true);
                },
                onError: (errors: Record<string, string>) => {
                    saveError.value = Object.values(errors)[0] ?? "Couldn't save this encounter.";
                    resolve(false);
                },
                onCancel: () => resolve(false),
                onFinish: () => {
                    saving.value = false;
                },
            };

            if (encounterId.value === null) {
                router.post(route('encounters.store'), payload, options);
            } else {
                router.put(route('encounters.update', encounterId.value), payload, options);
            }
        });

    const deleteEncounter = async () => {
        const id = encounterId.value;
        if (
            id === null ||
            !(await confirmAction({
                title: `Delete "${name.value}"?`,
                message: "The saved encounter is deleted for good. This can't be undone.",
                confirmLabel: 'Delete encounter',
                destructive: true,
                icon: Trash2,
            }))
        )
            return;

        router.delete(route('encounters.destroy', id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => startBlank(),
        });
    };

    // The fight as it is now, as a backup file. Built here rather than on the server so guests and
    // unsaved changes are covered.
    const exportFight = () => {
        const fightName = name.value.trim() || 'Untitled encounter';
        downloadBackup(
            fightName,
            fightBackup(
                { name: fightName, round: round.value, activeIndex: activeIndex.value, combatants: combatants.value, log: log.value },
                creaturesById.value,
            ),
        );
    };

    /**
     * Before replacing the fight in the tracker: true when it's fine to go ahead. With unsaved changes
     * the DM can keep editing, save first (go ahead only if the save works), or discard them. Guests
     * can't save, so they're offered an export instead.
     */
    const confirmDiscard = async (doing: string): Promise<boolean> => {
        const hasWork = isGuest ? combatants.value.length > 0 : isDirty.value;
        if (!hasWork) return true;

        const fightName = name.value.trim() || 'Untitled encounter';
        // Nowhere to save it yet (a guest, or an account still to be verified): offer an export instead.
        const exportInstead = !canSave;
        const choice = await ask(
            exportInstead
                ? {
                      title: 'Clear this fight?',
                      message: `${doing} clears "${fightName}". ${isGuest ? 'As a guest your fight' : 'Until your email is verified, your fight'} is only kept in this browser, so export it first if you want a copy.`,
                      confirmLabel: 'Clear it',
                      cancelLabel: 'Keep it',
                      alternativeLabel: 'Export first',
                      destructive: true,
                  }
                : {
                      title: 'Discard unsaved changes?',
                      message: `"${fightName}" has changes that haven't been saved. ${doing} loses them.`,
                      confirmLabel: 'Discard changes',
                      cancelLabel: 'Keep editing',
                      alternativeLabel: 'Save first',
                      destructive: true,
                  },
        );

        if (choice === 'alternative') {
            if (exportInstead) {
                exportFight();
                return true;
            }
            return saveEncounter();
        }
        return choice === 'confirm';
    };

    // Switching encounters from the dropdown.
    const switchTo = async (event: Event) => {
        const select = event.target as HTMLSelectElement;
        const target = props.savedEncounters.find((e) => String(e.id) === select.value);
        // Show the current one again while asking; it only changes once the new one loads.
        select.value = encounterId.value === null ? '' : String(encounterId.value);

        if (target && (await confirmDiscard('Opening another encounter'))) loadEncounter(target.id);
    };

    const newEncounter = async () => {
        if (await confirmDiscard('Starting a new encounter')) startBlank();
    };

    // --- Browser storage: the fight in progress survives a refresh for guests and signed-in users alike ---

    const storageKey = trackerStorageKey(user?.id ?? null);

    const restoreTracker = (): boolean => {
        const stored = readTracker(storageKey);
        if (!stored) return false;

        // If the saved copy was deleted elsewhere, keep the work as an unsaved encounter.
        const saved = props.savedEncounters.find((e) => e.id === stored.encounterId);
        encounterId.value = saved ? saved.id : null;
        name.value = stored.name;
        // Drop a campaign that's since been deleted (or belongs to someone else after logging out).
        campaignId.value = props.campaigns.some((c) => c.id === stored.campaignId) ? (stored.campaignId ?? null) : null;
        combatants.value = normalizeCombatants(stored.combatants, creaturesById.value);
        round.value = stored.round;
        activeIndex.value = stored.activeIndex;
        log.value = stored.log;
        // An unsaved encounter (or one stored before fingerprints) always counts as having changes.
        savedFingerprint.value = saved ? (stored.savedFingerprint ?? '') : '';
        return true;
    };

    const persistTracker = () =>
        writeTracker(storageKey, {
            version: 2,
            encounterId: encounterId.value,
            name: name.value,
            campaignId: campaignId.value,
            combatants: combatants.value,
            round: round.value,
            activeIndex: activeIndex.value,
            log: log.value,
            savedFingerprint: savedFingerprint.value,
        });

    /**
     * Picks up where this browser left off, unless a link asked for something else: a campaign's
     * "New encounter" button, or a specific encounter (?encounter=). Never loses unsaved work silently.
     */
    const start = () => {
        const restored = restoreTracker();
        const requestedId = Number(new URLSearchParams(window.location.search).get('encounter')) || null;

        // What the link asked for. Captured now: saving first reloads the page's props.
        let fromLink: (() => void) | null = null;
        const linkedEncounter = props.openEncounter;
        const linkedCampaign = props.newInCampaign;
        if (linkedCampaign) {
            fromLink = () => startBlank(linkedCampaign);
        } else if (requestedId && linkedEncounter?.id === requestedId && encounterId.value !== requestedId) {
            fromLink = () => openSaved(linkedEncounter);
        } else if (!restored) {
            if (props.openEncounter) openSaved(props.openEncounter);
            else startBlank();
        }

        // Nothing to lose (nothing restored, or an empty fight): follow the link straight away. Otherwise
        // ask once the page is up, since the dialog needs it.
        if (fromLink && (!restored || !combatants.value.length || (!isGuest && !isDirty.value))) {
            fromLink();
            fromLink = null;
        }
        onMounted(async () => {
            if (fromLink && (await confirmDiscard('Following this link'))) fromLink();
        });

        const stopWaitingForHistory = router.on('navigate', () => {
            stopWaitingForHistory();
            pageInHistory = true;
            clearUrlQuery();
        });
        onBeforeUnmount(stopWaitingForHistory);

        watch([encounterId, name, campaignId, combatants, round, activeIndex, log], persistTracker, { deep: true, immediate: true });
    };

    return {
        isGuest,
        saving,
        saveError,
        loadingEncounter,
        isDirty,
        saveEncounter,
        deleteEncounter,
        exportFight,
        switchTo,
        newEncounter,
        start,
    };
}
