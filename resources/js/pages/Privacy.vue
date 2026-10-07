<script setup lang="ts">
// A plain-language note on what the app keeps about people and why. Not a legal document: it
// describes what the code actually does, so change it whenever that changes.
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps<{
    contactEmail: string | null;
    // Whether errors are being reported to Sentry (only when it's configured).
    errorTracking: boolean;
}>();

const appName = usePage<SharedData>().props.appName;
</script>

<template>
    <Head title="Privacy" />

    <AppLayout>
        <article class="mx-auto w-full max-w-2xl space-y-6 p-4 py-8 text-sm leading-relaxed">
            <header class="space-y-1">
                <h1 class="text-2xl font-semibold tracking-tight">Privacy</h1>
                <p class="text-muted-foreground">What {{ appName }} keeps about you, and why. Last updated 7 October 2026.</p>
            </header>

            <section class="space-y-2">
                <h2 class="text-base font-semibold">Without an account</h2>
                <p>
                    You can use the tracker and compendium without signing up. Your fight is kept in your own browser (its local storage), along with
                    whether you prefer light or dark mode. None of that is sent to us.
                </p>
            </section>

            <section class="space-y-2">
                <h2 class="text-base font-semibold">Counting visits</h2>
                <p>
                    To know how many people use the site, each visitor is counted once a day. Your device and browser are turned into a one-way code
                    that changes every day, so it can't be traced back to you or link one day to the next, and it's deleted after two days. Only the
                    daily totals are kept. There are no tracking cookies, no stored IP addresses, and nothing is shared with an analytics company.
                </p>
            </section>

            <section class="space-y-2">
                <h2 class="text-base font-semibold">With an account</h2>
                <ul class="list-disc space-y-1 pl-5">
                    <li>Your name and email address, and your password (stored scrambled, so nobody can read it).</li>
                    <li>What you make: creatures and characters, saved encounters, campaigns, and your display settings.</li>
                    <li>The date you last used the site, so we can count how many accounts are active.</li>
                    <li>
                        In a campaign, your name and the character you've claimed are shown to its DM and the other players. If you run one, the fight
                        you're running is shown to its players while combat is on.
                    </li>
                </ul>
                <p>
                    A cookie keeps you logged in. There are no adverts, no tracking cookies and no analytics services. Your details aren't sold, and
                    are only passed to the services the app needs to run, like the one that sends its emails.
                </p>
            </section>

            <section class="space-y-2">
                <h2 class="text-base font-semibold">Emails</h2>
                <p>We only email you about your account: confirming your email address, and resetting your password when you ask.</p>
            </section>

            <section v-if="errorTracking" class="space-y-2">
                <h2 class="text-base font-semibold">When something breaks</h2>
                <p>
                    Technical details of errors (what failed and on which page) go to an error-tracking service, Sentry, so they can be fixed. They're
                    set up not to include your password, your email or the things you've made.
                </p>
            </section>

            <section class="space-y-2">
                <h2 class="text-base font-semibold">Taking your things with you, or deleting them</h2>
                <p>
                    You can download everything you've made as a backup file from the Backup menu on your
                    <Link :href="route('encounters.list')" class="font-medium underline underline-offset-4">Encounters</Link> page. Deleting your
                    account in Settings removes it and everything you've made straight away.
                </p>
            </section>

            <section v-if="contactEmail" class="space-y-2">
                <h2 class="text-base font-semibold">Questions</h2>
                <p>
                    Email <a :href="`mailto:${contactEmail}`" class="font-medium underline underline-offset-4">{{ contactEmail }}</a> about anything
                    to do with your data.
                </p>
            </section>
        </article>
    </AppLayout>
</template>
