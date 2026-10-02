<script setup lang="ts">
import { PhBug, PhCheck, PhHourglass, PhX } from '@phosphor-icons/vue';
import { computed } from 'vue';
import GitSourceAvatar from '@/components/git-sources/GitSourceAvatar.vue';
import { getGitProvider } from '@/data/git-providers';
import { t } from '@/lib/translate';
import type { TranslationKey } from '@/locales/en';
import type { GitSource, GitSourceSyncStatus } from '@/types/git-source';

const props = withDefaults(
    defineProps<{
        source: GitSource;
        selected?: boolean;
    }>(),
    { selected: false },
);

const provider = computed(() => getGitProvider(props.source.provider));
const statusLabels: Record<GitSourceSyncStatus, TranslationKey> = {
    idle: 'source.neverSynced',
    queued: 'sync.queued',
    syncing: 'sync.syncing',
    waiting: 'sync.waiting',
    succeeded: 'sync.succeeded',
    failed: 'sync.failed',
};
const statusIcon = computed(() => {
    switch (props.source.sync_status) {
        case 'queued':
        case 'syncing':
        case 'waiting':
            return PhHourglass;
        case 'failed':
            return PhBug;
        case 'succeeded':
            return PhCheck;
        default:
            return PhX;
    }
});
const statusLabel = computed(() => t(statusLabels[props.source.sync_status]));

defineEmits<{ select: [] }>();
</script>

<template>
    <button
        type="button"
        :aria-pressed="selected"
        :aria-label="`${t('sidebar.select', { name: source.name })}. ${statusLabel}`"
        :title="`${source.account} · ${statusLabel}`"
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
        <component
            :is="statusIcon"
            :size="18"
            weight="bold"
            :class="[
                'shrink-0',
                source.sync_status === 'failed' ? 'text-danger' : 'text-muted',
            ]"
            aria-hidden="true"
        />
    </button>
</template>
