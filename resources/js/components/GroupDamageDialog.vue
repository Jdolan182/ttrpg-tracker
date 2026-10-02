<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { sideInfo } from '@/lib/encounter';
import type { Combatant } from '@/types/tracker';
import { ref, watch } from 'vue';

const props = defineProps<{
    combatants: Combatant[];
    // Ticked to start with, e.g. whoever is selected.
    preselected: string[];
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    // `half` lists targets who take half (a successful save); healing ignores it.
    apply: [targets: { id: string; half: boolean }[], effect: 'damage' | 'heal', amount: number];
}>();

const chosen = ref<string[]>([]);
const halved = ref<string[]>([]);
const effect = ref<'damage' | 'heal'>('damage');
const amount = ref<number | string>('');
const error = ref('');

watch(open, (isOpen) => {
    if (!isOpen) return;
    chosen.value = [...props.preselected];
    halved.value = [];
    effect.value = 'damage';
    amount.value = '';
    error.value = '';
});

const toggled = (list: string[], id: string) => (list.includes(id) ? list.filter((x) => x !== id) : [...list, id]);
const toggleChosen = (id: string) => {
    chosen.value = toggled(chosen.value, id);
    error.value = '';
};
const toggleHalved = (id: string) => {
    halved.value = toggled(halved.value, id);
};

const submit = () => {
    const value = Number(amount.value);
    if (chosen.value.length === 0) {
        error.value = 'Choose at least one target.';
        return;
    }
    if (amount.value === '' || !Number.isInteger(value) || value < 1 || value > 100000) {
        error.value = 'Enter an amount of 1 or more.';
        return;
    }

    // Keep turn order so the history reads naturally.
    const targets = props.combatants
        .filter((c) => chosen.value.includes(c.id))
        .map((c) => ({ id: c.id, half: effect.value === 'damage' && halved.value.includes(c.id) }));
    emit('apply', targets, effect.value, value);
    open.value = false;
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-lg">
            <DialogHeader>
                <DialogTitle>Damage or heal several</DialogTitle>
                <DialogDescription>For area effects like a fireball. Tick ½ for anyone who made their save.</DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex rounded-md border border-border p-0.5" role="radiogroup" aria-label="Effect">
                        <button
                            v-for="option in [
                                { value: 'damage', label: 'Damage' },
                                { value: 'heal', label: 'Healing' },
                            ] as const"
                            :key="option.value"
                            type="button"
                            role="radio"
                            :aria-checked="effect === option.value"
                            class="rounded px-3 py-1 text-sm transition-colors"
                            :class="effect === option.value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-accent'"
                            @click="effect = option.value"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="group-amount">{{ effect === 'heal' ? 'HP regained' : 'Damage' }} each</Label>
                        <Input id="group-amount" v-model="amount" type="number" min="1" class="h-9 w-28" />
                    </div>
                </div>

                <div class="max-h-72 overflow-y-auto rounded-md border border-border" role="group" aria-label="Targets">
                    <div
                        v-for="combatant in combatants"
                        :key="combatant.id"
                        class="flex items-center gap-3 border-b border-border px-3 py-2 text-sm last:border-b-0"
                    >
                        <label class="flex min-w-0 flex-1 cursor-pointer items-center gap-3">
                            <input
                                type="checkbox"
                                class="size-4 rounded border-input"
                                :checked="chosen.includes(combatant.id)"
                                @change="toggleChosen(combatant.id)"
                            />
                            <span class="size-2 shrink-0 rounded-full" :class="sideInfo(combatant.side).dot" aria-hidden="true" />
                            <span class="truncate">{{ combatant.name }}</span>
                        </label>
                        <span class="shrink-0 text-xs tabular-nums text-muted-foreground">HP {{ combatant.hp }}/{{ combatant.maxHp }}</span>
                        <button
                            v-if="effect === 'damage'"
                            type="button"
                            class="shrink-0 rounded border px-1.5 text-xs transition-colors"
                            :class="
                                halved.includes(combatant.id)
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-border text-muted-foreground hover:bg-accent'
                            "
                            :aria-pressed="halved.includes(combatant.id)"
                            :aria-label="`${combatant.name} takes half`"
                            title="Takes half (made their save)"
                            @click="toggleHalved(combatant.id)"
                        >
                            ½
                        </button>
                    </div>
                </div>

                <p v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="open = false">Cancel</Button>
                    <Button type="submit">{{ effect === 'heal' ? 'Heal' : 'Damage' }} {{ chosen.length || '' }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
