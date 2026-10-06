<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppFooter from '@/components/AppFooter.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppShell from '@/components/AppShell.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ThrottleNotice from '@/components/ThrottleNotice.vue';
import VerifyEmailBanner from '@/components/VerifyEmailBanner.vue';
</script>

<template>
    <AppShell>
        <AppHeader />
        <VerifyEmailBanner />
        <ThrottleNotice />
        <AppContent>
            <slot />
        </AppContent>
        <AppFooter />
        <!-- Answers ask() / confirmAction() from any page -->
        <ConfirmDialog />
    </AppShell>
</template>
