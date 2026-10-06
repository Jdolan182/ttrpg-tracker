<script setup lang="ts">
// Shown to signed-in accounts that haven't verified their email yet: what's waiting on it, and a
// way to get the link again. Until then they can use the tracker and compendium like a guest.
import { Button } from '@/components/ui/button';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { MailCheck } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user);
const unverified = computed(() => !!user.value && !user.value.email_verified_at);

const sending = ref(false);
const sent = ref(false);
const resend = () =>
    router.post(
        route('verification.send'),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (sending.value = true),
            onSuccess: () => (sent.value = true),
            onFinish: () => (sending.value = false),
        },
    );
</script>

<template>
    <div
        v-if="unverified"
        class="border-b border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900 dark:bg-amber-950/60 dark:text-amber-100"
    >
        <div class="mx-auto flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2.5 text-sm md:max-w-7xl">
            <MailCheck class="size-4 shrink-0" />
            <p class="flex-1">
                <span class="font-medium">Check your email to finish setting up your account.</span>
                We sent a link to {{ user?.email }}. Until you click it you can use the tracker, but not save encounters, make creatures or join
                campaigns.
            </p>
            <span v-if="sent" class="text-xs font-medium">New link sent.</span>
            <Button v-else size="sm" variant="outline" class="h-7 bg-background" :disabled="sending" @click="resend">
                {{ sending ? 'Sending…' : 'Send the link again' }}
            </Button>
        </div>
    </div>
</template>
