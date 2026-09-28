import type { StatDisplay } from '@/lib/stats';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/** The signed-in user's stat display setting; guests get the default "18 (+4)". */
export function useStatDisplay() {
    const page = usePage<SharedData>();
    return computed<StatDisplay>(() => page.props.auth.user?.stat_display ?? 'score_modifier');
}
