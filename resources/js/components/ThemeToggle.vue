<script setup lang="ts">
// Switches between light and dark, in the header for everyone, guests included.
import { Button } from '@/components/ui/button';
import { useTheme } from '@/composables/useTheme';
import { Moon, Sun } from 'lucide-vue-next';
import { computed } from 'vue';

const { theme, toggleTheme } = useTheme();
const label = computed(() => (theme.value === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'));
</script>

<template>
    <Button variant="ghost" size="icon" class="size-9" :aria-label="label" :title="label" @click="toggleTheme">
        <!-- Shows what you'd switch to -->
        <Sun v-if="theme === 'dark'" class="size-5" />
        <Moon v-else class="size-5" />
    </Button>
</template>
