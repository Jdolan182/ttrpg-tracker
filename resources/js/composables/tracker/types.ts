import type { LogEntry } from '@/lib/combatLog';
import type { Combatant } from '@/types/tracker';
import type { Ref } from 'vue';

// The fight itself, as the tracker's composables share it. Everything else (which encounter it is,
// its name and campaign) belongs to the encounter around it.
export interface FightState {
    combatants: Ref<Combatant[]>;
    // 0 while setting up, then 1, 2, 3…
    round: Ref<number>;
    activeIndex: Ref<number>;
    log: Ref<LogEntry[]>;
}
