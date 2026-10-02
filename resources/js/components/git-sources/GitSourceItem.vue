<script setup lang="ts">
import { PhCheck } from '@phosphor-icons/vue';
import { t } from '@/lib/translate';
import type { GitSourceProvider } from '@/types/git-source';

withDefaults(
    defineProps<{
        provider: GitSourceProvider;
        account: string;
        selected?: boolean;
    }>(),
    { selected: false },
);

defineEmits<{ select: [] }>();
</script>

<template>
    <button
        type="button"
        :aria-pressed="selected"
        :aria-label="t('sidebar.select', { name: account })"
        :title="account"
        :class="[
            'focus-ring flex w-full min-w-0 items-center gap-3 rounded-xl p-3 text-left transition-colors',
            selected
                ? 'bg-selection text-selection-foreground'
                : 'text-foreground hover:bg-hover active:bg-hover',
        ]"
        @click="$emit('select')"
    >
        <span
            :class="[
                'flex size-9 shrink-0 items-center justify-center rounded-[10px]',
                selected ? 'bg-surface/65' : 'bg-surface',
            ]"
        >
            <component
                :is="provider.icon"
                :size="21"
                weight="fill"
                aria-hidden="true"
            />
        </span>
        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span class="truncate text-sm font-semibold">{{ account }}</span>
            <span
                :class="[
                    'text-xs',
                    selected ? 'text-selection-foreground' : 'text-muted',
                ]"
            >
                {{ provider.name }}
            </span>
        </span>
        <PhCheck v-if="selected" :size="17" weight="bold" aria-hidden="true" />
    </button>
</template>
