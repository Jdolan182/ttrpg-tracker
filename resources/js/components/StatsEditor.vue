<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useStatDisplay } from '@/composables/useStatDisplay';
import { formatModifier, modifier, type StatRow } from '@/lib/stats';
import { Plus, X } from 'lucide-vue-next';

const rows = defineModel<StatRow[]>({ required: true });

const statDisplay = useStatDisplay();

const modifierHint = (value: number | string) => {
    const score = Number(value);
    return value !== '' && Number.isInteger(score) ? formatModifier(modifier(score)) : '';
};
</script>

<template>
    <div class="space-y-2">
        <!-- One per row on phones, two from sm up: each needs room for name, value, modifier and remove. -->
        <div class="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2">
            <div v-for="(row, index) in rows" :key="index" class="flex min-w-0 items-center gap-1.5">
                <!-- w-0 + flex-1: Input adds w-full by default, which would push the rest of the row out of view -->
                <Input
                    v-model="row.label"
                    maxlength="20"
                    class="h-8 w-0 min-w-0 flex-1 px-2 text-xs font-medium"
                    :aria-label="`Stat ${index + 1} name`"
                />
                <Input
                    v-model="row.value"
                    type="number"
                    class="h-8 w-16 shrink-0 px-2"
                    placeholder="–"
                    :aria-label="`${row.label || `Stat ${index + 1}`} value`"
                />
                <span v-if="statDisplay !== 'score'" class="w-7 shrink-0 text-xs tabular-nums text-muted-foreground">{{
                    modifierHint(row.value)
                }}</span>
                <button
                    type="button"
                    class="shrink-0 rounded p-0.5 text-muted-foreground hover:text-foreground"
                    :aria-label="`Remove ${row.label || 'stat'}`"
                    @click="rows.splice(index, 1)"
                >
                    <X class="size-3.5" />
                </button>
            </div>
        </div>
        <Button type="button" variant="ghost" size="sm" class="h-7 px-2 text-xs" @click="rows.push({ label: '', value: '' })">
            <Plus />
            Add stat
        </Button>
    </div>
</template>
