<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppShell from '@/components/AppShell.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import VerifyEmailBanner from '@/components/VerifyEmailBanner.vue';
</script>

<template>
    <AppShell>
        <AppHeader />
        <VerifyEmailBanner />
        <AppContent>
            <slot />
        </AppContent>
        <!-- Answers ask() / confirmAction() from any page -->
        <ConfirmDialog />
    </AppShell>
</template>
