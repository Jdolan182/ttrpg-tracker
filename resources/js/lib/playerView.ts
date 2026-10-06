// What players see of a fight. Mirrors App\Support\PlayerView, which does the same on the server for
// players' own devices; this one is for the DM's screen and the same-computer second window.
import { dmOnlyLogTypes, type LogEntry } from '@/lib/combatLog';
import type { Combatant, EnemyHpDisplay, HealthStatus, PlayerViewCombatant, PlayerViewFight } from '@/types/tracker';

const friendlySides = ['player', 'ally'];

const statusOf = (combatant: Combatant): HealthStatus => {
    if (combatant.hp > 0) return combatant.hp * 2 <= combatant.maxHp ? 'bloodied' : 'healthy';
    if (combatant.side !== 'player') return 'down';
    if ((combatant.deathSaves?.failures ?? 0) >= 3) return 'dead';
    if ((combatant.deathSaves?.successes ?? 0) >= 3) return 'stable';
    return 'dying';
};

const viewOf = (combatant: Combatant, active: boolean, enemyHp: EnemyHpDisplay): PlayerViewCombatant => {
    const status = statusOf(combatant);
    const showNumbers = friendlySides.includes(combatant.side) || enemyHp === 'exact';
    // With HP hidden, players still see an enemy drop: that's plain at the table anyway.
    const showStatus = showNumbers || enemyHp === 'bands' || status === 'down';

    return {
        id: combatant.id,
        name: combatant.name,
        side: combatant.side,
        initiative: combatant.initiative,
        active,
        hp: showNumbers ? combatant.hp : null,
        maxHp: showNumbers ? combatant.maxHp : null,
        tempHp: showNumbers ? (combatant.tempHp ?? 0) : null,
        status: showStatus ? status : null,
        conditions: combatant.conditions.map((name) => ({ name, rounds: combatant.durations?.[name] ?? null })),
        concentrating: !!combatant.concentrating,
    };
};

// Players get the latest part of the history only. Mirrors PlayerView::HISTORY_LIMIT.
export const HISTORY_LIMIT = 150;

/**
 * The history without anything players shouldn't know: entries about combatants while they were
 * hidden, setup, the DM's bookkeeping (hide/reveal, recharge rolls), and (unless enemy HP is exact) how much enemies
 * healed. Damage stays: players hear that at the table.
 *
 * Who was hidden when is worked out by walking back from now: a "hidden" entry means they weren't
 * hidden before it, a "revealed" one that they were.
 */
const historyFor = (log: LogEntry[], combatants: Combatant[], enemyHp: EnemyHpDisplay): LogEntry[] => {
    const hidden = new Set(combatants.filter((c) => c.hidden).map((c) => c.name));
    const sides = new Map(combatants.map((c) => [c.name, c.side]));
    const entries: LogEntry[] = [];

    for (const original of log.slice(-HISTORY_LIMIT).reverse()) {
        const entry = { ...original };
        let targets = entry.targets ?? [];

        if (entry.type === 'hidden' || entry.type === 'revealed') {
            for (const name of targets) {
                if (entry.type === 'hidden') hidden.delete(name);
                else hidden.add(name);
            }
            continue;
        }
        if (entry.round < 1 || dmOnlyLogTypes.includes(entry.type)) continue;

        // A hidden combatant's turn still happened; players just don't learn whose it was.
        if (entry.type === 'turn' && hidden.has(entry.actor ?? '')) {
            delete entry.actor;
            entries.push(entry);
            continue;
        }
        if (entry.actor !== undefined && hidden.has(entry.actor)) continue;
        // E.g. a fireball that also hit someone hidden: keep it, just without them.
        if (targets.length) {
            targets = targets.filter((name) => !hidden.has(name));
            if (!targets.length) continue;
            entry.targets = targets;
        }

        const isHealing = entry.type === 'heal' || entry.type === 'temp_hp' || (entry.type === 'action' && entry.effect === 'heal');
        const onEnemies = targets.some((name) => !friendlySides.includes(sides.get(name) ?? ''));
        if (isHealing && onEnemies && enemyHp !== 'exact') {
            delete entry.amount;
            delete entry.effect;
        }

        entries.push(entry);
    }

    return entries.reverse();
};

/** The fight as players see it, or null while it's being set up (players never see setup). */
export const playerView = (
    fight: { name: string; round: number; activeIndex: number; combatants: Combatant[]; log: LogEntry[] },
    enemyHp: EnemyHpDisplay,
): PlayerViewFight | null => {
    if (fight.round < 1) return null;

    return {
        name: fight.name,
        round: fight.round,
        combatants: fight.combatants.flatMap((combatant, index) =>
            combatant.hidden ? [] : [viewOf(combatant, index === fight.activeIndex, enemyHp)],
        ),
        log: historyFor(fight.log, fight.combatants, enemyHp),
    };
};

// The same-computer second window reads the DM's player view from browser storage, written by the
// tracker on every change. Only the filtered view goes there, never the full fight.
export const playerViewStorageKey = (userId: number | null) => `ttrpg-tracker:player-view:${userId ? `user-${userId}` : 'guest'}`;

export const writePlayerView = (key: string, view: PlayerViewFight | null) => {
    try {
        window.localStorage.setItem(key, JSON.stringify(view));
    } catch {
        // Storage unavailable: the second window just won't update.
    }
};

export const readPlayerView = (key: string): PlayerViewFight | null => {
    try {
        const raw = window.localStorage.getItem(key);
        const view = raw ? (JSON.parse(raw) as PlayerViewFight | null) : null;
        if (!view || !Array.isArray(view.combatants)) return null;
        // Written before players could see the history.
        if (!Array.isArray(view.log)) view.log = [];
        return view;
    } catch {
        return null;
    }
};
