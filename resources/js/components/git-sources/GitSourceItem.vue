<script setup lang="ts">
import { PhCheck } from '@phosphor-icons/vue';
import { computed } from 'vue';
import GitSourceAvatar from '@/components/git-sources/GitSourceAvatar.vue';
import { getGitProvider } from '@/data/git-providers';
import { t } from '@/lib/translate';
import type { GitSource } from '@/types/git-source';

const props = withDefaults(
    defineProps<{
        source: GitSource;
        selected?: boolean;
    }>(),
    { selected: false },
);

const provider = computed(() => getGitProvider(props.source.provider));

defineEmits<{ select: [] }>();
</script>

<template>
    <button
        type="button"
        :aria-pressed="selected"
        :aria-label="t('sidebar.select', { name: source.name })"
        :title="source.account"
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
            <GitSourceAvatar
                :provider="source.provider"
                :url="source.avatar_url"
            />
        </span>
        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span class="truncate text-sm font-semibold">{{
                source.name
            }}</span>
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
