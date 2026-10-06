<script setup lang="ts">
// Small print at the bottom of every app page: an invitation to send feedback, and the privacy note.
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { MessageSquareHeart } from 'lucide-vue-next';

const page = usePage<SharedData>();
</script>

<template>
    <footer class="border-t border-sidebar-border/80">
        <div class="mx-auto flex flex-wrap items-center gap-x-5 gap-y-2 px-4 py-4 text-xs text-muted-foreground md:max-w-7xl">
            <span>{{ page.props.appName }}</span>
            <span v-if="page.props.feedbackUrl" class="inline-flex flex-wrap items-center gap-x-1.5">
                This is still going, so tell me what is good and what isn't or suggestions.
                <a
                    :href="page.props.feedbackUrl"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-1 font-medium text-foreground hover:text-primary"
                >
                    <MessageSquareHeart class="size-3.5" />
                    Send feedback
                </a>
            </span>
            <Link :href="route('privacy')" class="hover:text-foreground">Privacy</Link>
            <span class="ml-auto">Monster stat blocks from the SRD 5.1 (CC-BY-4.0)</span>
        </div>
    </footer>
</template>
