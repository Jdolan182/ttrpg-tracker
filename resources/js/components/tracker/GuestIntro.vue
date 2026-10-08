<script lang="ts">
const STORAGE_KEY = 'ttrpg-tracker:intro-dismissed';

/** Whether this browser has already closed the intro. Storage can be blocked, which just shows it again. */
export const introDismissed = () => {
    try {
        return localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
};
</script>

<script setup lang="ts">
// What Turnkeeper is, for someone who's just arrived: the guest lands straight in the tracker, so this
// says what it does and what an account adds. It's also the page's main heading for search engines.
// Dismissed once, it stays dismissed in this browser (the page then shows a one-line guest note).
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/vue3';
import { X } from 'lucide-vue-next';

const open = defineModel<boolean>('open', { required: true });

const dismiss = () => {
    open.value = false;
    try {
        localStorage.setItem(STORAGE_KEY, '1');
    } catch {
        // Not remembered, so it shows again next visit: harmless.
    }
};
</script>

<template>
    <section v-if="open" class="relative rounded-xl border border-border bg-card p-5 shadow-sm sm:p-6">
        <button
            type="button"
            class="absolute right-3 top-3 rounded-md p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground"
            aria-label="Close the introduction"
            @click="dismiss"
        >
            <X class="size-4" />
        </button>
        <div class="flex gap-4 pr-6">
            <div class="hidden size-12 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary sm:flex">
                <AppLogoIcon class="size-7" />
            </div>
            <div class="space-y-3">
                <h1 class="font-display text-2xl font-semibold tracking-tight">A free encounter tracker for D&amp;D and any other tabletop RPG</h1>
                <p class="max-w-3xl text-sm text-muted-foreground">
                    Roll initiative, track hit points, conditions and concentration, and let it count down recharges, cooldowns and legendary actions
                    for you. Over 300 SRD monsters are built in, and it works with any game: name your own stats. Run a campaign and your players can
                    follow the fight live on their phones.
                </p>
                <p class="max-w-3xl text-sm text-muted-foreground">
                    Try it right here, no account needed: your fight is kept in this browser. A free account lets you save encounters, make your own
                    creatures and invite your players.
                </p>
                <div class="flex flex-wrap gap-2 pt-1">
                    <Button as-child>
                        <Link :href="route('register')">Create a free account</Link>
                    </Button>
                    <Button variant="outline" @click="dismiss">Try it first</Button>
                </div>
            </div>
        </div>
    </section>
</template>
