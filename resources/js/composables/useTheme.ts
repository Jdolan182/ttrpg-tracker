import { ref } from 'vue';

// Light or dark, light by default. Kept in this browser only (not the account), so it works the
// same for guests. resources/views/app.blade.php applies a saved dark theme before the page draws,
// so there's no flash of light first.
export type Theme = 'light' | 'dark';

const STORAGE_KEY = 'appearance';

// One shared value, so the header button and anything else always agree.
const theme = ref<Theme>('light');

const saved = (): Theme => {
    try {
        // Anything else saved here (an older "system" setting) counts as light.
        return localStorage.getItem(STORAGE_KEY) === 'dark' ? 'dark' : 'light';
    } catch {
        return 'light';
    }
};

const apply = () => document.documentElement.classList.toggle('dark', theme.value === 'dark');

export function initializeTheme() {
    theme.value = saved();
    apply();
}

export function useTheme() {
    const toggleTheme = () => {
        theme.value = theme.value === 'dark' ? 'light' : 'dark';
        try {
            localStorage.setItem(STORAGE_KEY, theme.value);
        } catch {
            // Storage blocked: the theme still changes, it just won't be remembered.
        }
        apply();
    };

    return { theme, toggleTheme };
}
