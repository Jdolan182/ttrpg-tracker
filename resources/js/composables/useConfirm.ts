import type { Component } from 'vue';
import { ref, shallowRef } from 'vue';

// In-app replacement for window.confirm: one ConfirmDialog (in the app layout) shows whichever
// question was asked last, and the caller awaits the answer.

export type ConfirmChoice = 'confirm' | 'alternative' | 'cancel';

export interface ConfirmOptions {
    title: string;
    message?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    // A third way out, e.g. "Save first" next to "Discard changes".
    alternativeLabel?: string;
    // Red confirm button, for things that can't be undone or lose work.
    destructive?: boolean;
    icon?: Component;
}

interface Pending extends ConfirmOptions {
    resolve: (choice: ConfirmChoice) => void;
}

export const pendingConfirm = shallowRef<Pending | null>(null);
export const confirmOpen = ref(false);

/** Asks, and resolves to which button was chosen. Closing the dialog counts as cancel. */
export const ask = (options: ConfirmOptions) =>
    new Promise<ConfirmChoice>((resolve) => {
        // A new question replaces one that's still open, which counts as cancelled.
        pendingConfirm.value?.resolve('cancel');
        pendingConfirm.value = { ...options, resolve };
        confirmOpen.value = true;
    });

/** For yes/no questions: true when confirmed. */
export const confirmAction = async (options: ConfirmOptions) => (await ask(options)) === 'confirm';

/** Called by ConfirmDialog when a button is chosen or the dialog closes. */
export const answer = (choice: ConfirmChoice) => {
    const pending = pendingConfirm.value;
    if (!pending) return;
    pendingConfirm.value = null;
    confirmOpen.value = false;
    pending.resolve(choice);
};
