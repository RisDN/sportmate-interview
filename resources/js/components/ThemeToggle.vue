<script setup lang="ts">
import { PhMoon, PhSun } from '@phosphor-icons/vue';
import { t } from '@/lib/translate';
import type { ThemePreference } from '@/types/theme';

defineProps<{ theme: ThemePreference }>();
defineEmits<{ toggle: [] }>();
</script>

<template>
    <button
        type="button"
        class="focus-ring flex size-10 shrink-0 items-center justify-center rounded-full border border-line bg-surface text-muted transition-colors hover:bg-hover hover:text-foreground"
        :aria-label="t('theme.toggle')"
        :title="t('theme.toggle')"
        @click="$emit('toggle')"
    >
        <PhSun v-if="theme === 'dark'" :size="20" aria-hidden="true" />
        <PhMoon v-else-if="theme === 'light'" :size="20" aria-hidden="true" />
        <template v-else>
            <PhSun
                :size="20"
                class="hidden [@media(prefers-color-scheme:dark)]:block"
                aria-hidden="true"
            />
            <PhMoon
                :size="20"
                class="[@media(prefers-color-scheme:dark)]:hidden"
                aria-hidden="true"
            />
        </template>
    </button>
</template>
