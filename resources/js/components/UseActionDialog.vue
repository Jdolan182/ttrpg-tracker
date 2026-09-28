<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { limitLabel, sideInfo, type ActionEffect } from '@/lib/encounter';
import type { Combatant, CreatureAction } from '@/types/tracker';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    actor: Combatant | undefined;
    action: CreatureAction | undefined;
    // Null for unlimited actions.
    usesLeft: number | null;
    combatants: Combatant[];
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    use: [targetIds: string[], effect: ActionEffect, amount: number];
}>();

const targetIds = ref<string[]>([]);
const effect = ref<ActionEffect>('none');
const amount = ref<number | string>('');
const error = ref('');

const effects: { value: ActionEffect; label: string }[] = [
    { value: 'none', label: 'No effect' },
    { value: 'damage', label: 'Damage' },
    { value: 'heal', label: 'Healing' },
];

// Start each use clean, guessing the effect from the action's text ("Hit: 5 slashing damage").
watch(open, (isOpen) => {
    if (!isOpen) return;
    targetIds.value = [];
    amount.value = '';
    error.value = '';
    const text = props.action?.description.toLowerCase() ?? '';
    effect.value = /\bdamage\b/.test(text) ? 'damage' : /\b(heal|regains?|hit points)\b/.test(text) ? 'heal' : 'none';
});

const toggleTarget = (id: string) => {
    targetIds.value = targetIds.value.includes(id) ? targetIds.value.filter((t) => t !== id) : [...targetIds.value, id];
    error.value = '';
};

const outOfUses = computed(() => props.usesLeft === 0);

const submit = () => {
    const value = Number(amount.value);
    if (effect.value !== 'none') {
        if (targetIds.value.length === 0) {
            error.value = `Choose who takes the ${effect.value === 'heal' ? 'healing' : 'damage'}.`;
            return;
        }
        if (amount.value === '' || !Number.isInteger(value) || value < 1 || value > 100000) {
            error.value = 'Enter an amount of 1 or more.';
            return;
        }
    }

    // Keep targets in turn order so the history reads naturally.
    const ordered = props.combatants.filter((c) => targetIds.value.includes(c.id)).map((c) => c.id);
    emit('use', ordered, effect.value, effect.value === 'none' ? 0 : value);
    open.value = false;
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent v-if="actor && action" class="max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ actor.name }}: {{ action.name }}</DialogTitle>
                <DialogDescription class="max-h-24 overflow-y-auto">{{ action.description }}</DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <p
                    v-if="outOfUses"
                    class="rounded-md bg-amber-100 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                    role="status"
                >
                    No uses left ({{ limitLabel(action) }}). You can still use it; it'll be recorded as normal.
                </p>

                <fieldset class="space-y-1.5">
                    <legend class="text-sm font-medium">Targets</legend>
                    <div class="max-h-56 overflow-y-auto rounded-md border border-border">
                        <label
                            v-for="combatant in combatants"
                            :key="combatant.id"
                            class="flex cursor-pointer items-center gap-3 border-b border-border px-3 py-2 text-sm last:border-b-0 hover:bg-accent"
                        >
                            <input
                                type="checkbox"
                                class="size-4 rounded border-input"
                                :checked="targetIds.includes(combatant.id)"
                                @change="toggleTarget(combatant.id)"
                            />
                            <span class="size-2 shrink-0 rounded-full" :class="sideInfo(combatant.side).dot" aria-hidden="true" />
                            <span class="min-w-0 flex-1 truncate">
                                {{ combatant.name }}
                                <span v-if="combatant.id === actor.id" class="text-xs text-muted-foreground">(self)</span>
                            </span>
                            <span class="shrink-0 text-xs tabular-nums text-muted-foreground">HP {{ combatant.hp }}/{{ combatant.maxHp }}</span>
                        </label>
                    </div>
                </fieldset>

                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex rounded-md border border-border p-0.5" role="radiogroup" aria-label="Effect">
                        <button
                            v-for="option in effects"
                            :key="option.value"
                            type="button"
                            role="radio"
                            :aria-checked="effect === option.value"
                            class="rounded px-3 py-1 text-sm transition-colors"
                            :class="effect === option.value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-accent'"
                            @click="
                                effect = option.value;
                                error = '';
                            "
                        >
                            {{ option.label }}
                        </button>
                    </div>
                    <div v-if="effect !== 'none'" class="grid gap-1.5">
                        <Label for="action-amount">{{ effect === 'heal' ? 'HP regained' : 'Damage' }} each</Label>
                        <Input id="action-amount" v-model="amount" type="number" min="1" class="h-9 w-28" />
                    </div>
                </div>

                <p v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="open = false">Cancel</Button>
                    <Button type="submit">Use {{ action.name }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
