<script setup lang="ts">
// The one dialog behind ask() / confirmAction() in composables/useConfirm.ts. Lives in the app layout.
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { answer, confirmOpen, pendingConfirm } from '@/composables/useConfirm';
import { CircleHelp, TriangleAlert } from 'lucide-vue-next';
import { ref } from 'vue';

const safeButton = ref<InstanceType<typeof Button>>();

// Start on the safe choice, so a stray Enter never throws work away.
const focusSafe = (event: Event) => {
    event.preventDefault();
    (safeButton.value?.$el as HTMLElement | undefined)?.focus();
};

const onOpenChange = (open: boolean) => {
    if (!open) answer('cancel');
};
</script>

<template>
    <Dialog :open="confirmOpen" @update:open="onOpenChange">
        <DialogContent v-if="pendingConfirm" class="max-w-md" @open-auto-focus="focusSafe">
            <div class="flex gap-4">
                <div
                    class="flex size-10 shrink-0 items-center justify-center rounded-full"
                    :class="pendingConfirm.destructive ? 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-400' : 'bg-primary/10 text-primary'"
                    aria-hidden="true"
                >
                    <component :is="pendingConfirm.icon ?? (pendingConfirm.destructive ? TriangleAlert : CircleHelp)" class="size-5" />
                </div>
                <DialogHeader class="space-y-1.5 text-left">
                    <DialogTitle class="font-display text-lg">{{ pendingConfirm.title }}</DialogTitle>
                    <DialogDescription v-if="pendingConfirm.message" class="whitespace-pre-line">{{ pendingConfirm.message }}</DialogDescription>
                </DialogHeader>
            </div>
            <DialogFooter class="gap-2">
                <Button ref="safeButton" variant="outline" @click="answer('cancel')">{{ pendingConfirm.cancelLabel ?? 'Cancel' }}</Button>
                <Button v-if="pendingConfirm.alternativeLabel" variant="secondary" @click="answer('alternative')">
                    {{ pendingConfirm.alternativeLabel }}
                </Button>
                <Button :variant="pendingConfirm.destructive ? 'destructive' : 'default'" @click="answer('confirm')">
                    {{ pendingConfirm.confirmLabel ?? 'Continue' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
