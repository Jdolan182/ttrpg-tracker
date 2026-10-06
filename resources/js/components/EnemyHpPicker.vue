<script setup lang="ts">
// How much of enemies' HP a campaign's players see in the player view. Used when creating a
// campaign and when editing it. Mirrors Campaign::ENEMY_HP.
import type { EnemyHpDisplay } from '@/types/tracker';

const model = defineModel<EnemyHpDisplay>({ required: true });

const options: { value: EnemyHpDisplay; label: string; hint: string }[] = [
    { value: 'bands', label: 'Bands', hint: 'Healthy, Bloodied or Down' },
    { value: 'exact', label: 'Exact HP', hint: 'The real numbers' },
    { value: 'hidden', label: 'Hidden', hint: "Players can't tell how hurt enemies are" },
];
</script>

<template>
    <fieldset class="space-y-1.5">
        <legend class="text-sm font-medium">What players see of enemy HP</legend>
        <div class="flex flex-wrap gap-2">
            <label
                v-for="option in options"
                :key="option.value"
                class="flex cursor-pointer items-start gap-2 rounded-md border px-3 py-2 text-sm"
                :class="model === option.value ? 'border-primary ring-1 ring-primary' : 'border-border'"
            >
                <input v-model="model" type="radio" name="enemy_hp" :value="option.value" class="mt-1" />
                <span>
                    <span class="block font-medium">{{ option.label }}</span>
                    <span class="block text-xs text-muted-foreground">{{ option.hint }}</span>
                </span>
            </label>
        </div>
        <p class="text-xs text-muted-foreground">Allies' and players' own HP always show.</p>
    </fieldset>
</template>
