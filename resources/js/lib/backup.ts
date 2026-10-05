// Backups in our own JSON format. Mirrors App\Support\Backup, which builds the full backup and does
// every import; this builds the same file for the fight open in the tracker, so guests can export too.
import type { LogEntry } from '@/lib/combatLog';
import type { Combatant, Creature } from '@/types/tracker';

export const BACKUP_FORMAT = 'ttrpg-tracker-backup';
export const BACKUP_VERSION = 1;

const ref = (id: number) => `c${id}`;

/** The fight in the tracker as a backup: the encounter plus the creatures it uses. */
export const fightBackup = (
    fight: { name: string; round: number; activeIndex: number; combatants: Combatant[]; log: LogEntry[] },
    creaturesById: Map<number, Creature>,
) => {
    const used = [...new Set(fight.combatants.map((c) => c.creatureId).filter((id): id is number => id !== null))]
        .map((id) => creaturesById.get(id))
        .filter((creature): creature is Creature => !!creature);

    return {
        format: BACKUP_FORMAT,
        version: BACKUP_VERSION,
        exportedAt: new Date().toISOString(),
        creatures: used.map(({ id, ...creature }) =>
            // SRD creatures by name only: they're matched to the SRD on import.
            creature.source === 'srd' ? { ref: ref(id), source: 'srd', name: creature.name } : { ref: ref(id), ...creature },
        ),
        encounters: [
            {
                name: fight.name,
                round: fight.round,
                activeIndex: fight.activeIndex,
                combatants: fight.combatants.map(({ creatureId, ...combatant }) => ({
                    creature: creatureId !== null && creaturesById.has(creatureId) ? ref(creatureId) : null,
                    ...combatant,
                })),
                log: fight.log,
            },
        ],
    };
};

const slug = (text: string) =>
    text
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '') || 'encounter';

/** Saves data as a .json file, named like the server's downloads. */
export const downloadBackup = (name: string, data: unknown) => {
    const blob = new Blob([JSON.stringify(data, null, 4)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `ttrpg-tracker-${slug(name)}-${new Date().toISOString().slice(0, 10)}.json`;
    link.click();
    URL.revokeObjectURL(url);
};
