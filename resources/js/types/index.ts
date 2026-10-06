import type { StatDisplay } from '@/lib/stats';
import type { PageProps } from '@inertiajs/core';
import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User | null;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

// The props every page gets (HandleInertiaRequests::share). Extends Inertia's own so usePage<SharedData>() type-checks.
export interface SharedData extends PageProps {
    appName: string;
    // Where the footer's Feedback link goes; null hides it.
    feedbackUrl: string | null;
    auth: Auth;
    // Usage against the account's limits (config/plans.php). Null for guests.
    limits: Record<'creatures' | 'encounters' | 'campaigns' | 'campaigns_joined', { used: number; limit: number }> | null;
    flash: {
        savedEncounterId: number | null;
        // What an import added.
        imported: string | null;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    stat_display: StatDisplay;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}
