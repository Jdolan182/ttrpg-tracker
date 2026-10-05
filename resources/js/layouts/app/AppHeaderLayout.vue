<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppShell from '@/components/AppShell.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell class="flex-col">
        <AppHeader />
        <AppContent>
            <slot />
        </AppContent>
        <!-- Answers ask() / confirmAction() from any page -->
        <ConfirmDialog />
    </AppShell>
</template>
