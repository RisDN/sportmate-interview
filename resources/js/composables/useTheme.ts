import { readonly, ref } from 'vue';
import type { ThemePreference } from '@/types/theme';

export function useTheme(initialPreference: ThemePreference) {
    // Keep the state local to this page, including each server render.
    const theme = ref<ThemePreference>(initialPreference);

    function setTheme(preference: 'light' | 'dark') {
        theme.value = preference;
        document.documentElement.dataset.theme = preference;

        const secure = window.location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = `theme=${preference}; Path=/; Max-Age=31536000; SameSite=Lax${secure}`;
    }

    function toggleTheme() {
        const isDark =
            theme.value === 'dark' ||
            (theme.value === 'system' &&
                window.matchMedia('(prefers-color-scheme: dark)').matches);

        setTheme(isDark ? 'light' : 'dark');
    }

    return { theme: readonly(theme), toggleTheme };
}
