<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { useStatDisplay } from '@/composables/useStatDisplay';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { statDisplays, statParts, type StatDisplay } from '@/lib/stats';
import { Head, useForm } from '@inertiajs/vue3';

const current = useStatDisplay();

const form = useForm({
    stat_display: current.value,
});

// A few stats to preview each option with.
const preview = [
    { label: 'STR', value: 18 },
    { label: 'DEX', value: 12 },
    { label: 'INT', value: 7 },
];

// Saves as soon as an option is picked; there's no Save button.
const choose = (value: StatDisplay) => {
    if (form.processing || value === form.stat_display) return;
    form.stat_display = value;
    form.patch(route('display.update'), { preserveScroll: true });
};
</script>

<template>
    <AppLayout>
        <Head title="Display settings" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Stat display"
                    description="How stats appear on stat blocks. Modifiers use the D&D rule: every 2 points above 10 is +1."
                />

                <div class="grid gap-3" role="radiogroup" aria-label="Stat display">
                    <button
                        v-for="option in statDisplays"
                        :key="option.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.stat_display === option.value"
                        class="flex items-center gap-4 rounded-lg border p-4 text-left transition-colors hover:bg-accent"
                        :class="form.stat_display === option.value ? 'border-primary ring-1 ring-primary' : 'border-border'"
                        @click="choose(option.value)"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">{{ option.label }}</span>
                            <span class="block text-sm text-muted-foreground">{{ option.example }}</span>
                        </span>
                        <span class="flex shrink-0 gap-1.5" aria-hidden="true">
                            <span
                                v-for="stat in preview"
                                :key="stat.label"
                                class="w-16 rounded-md border border-border px-2 py-1 text-center text-sm"
                            >
                                <span class="block text-xs text-muted-foreground">{{ stat.label }}</span>
                                <span class="font-medium">{{ statParts(stat.value, option.value).main }}</span>
                                <span v-if="statParts(stat.value, option.value).extra" class="ml-0.5 text-xs text-muted-foreground">
                                    ({{ statParts(stat.value, option.value).extra }})
                                </span>
                            </span>
                        </span>
                    </button>
                </div>

                <InputError :message="form.errors.stat_display" />
                <p v-if="form.recentlySuccessful" class="text-sm text-muted-foreground" role="status">Saved.</p>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
