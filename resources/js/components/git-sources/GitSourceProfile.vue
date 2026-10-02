<script setup lang="ts">
import {
    PhArrowsClockwise,
    PhCircleNotch,
    PhClock,
    PhWarningCircle,
} from '@phosphor-icons/vue';
import { computed } from 'vue';
import GitSourceAvatar from '@/components/git-sources/GitSourceAvatar.vue';
import { getGitProvider } from '@/data/git-providers';
import { errorMessage } from '@/lib/api-errors';
import { dateString, dateTimeString, isoDateString } from '@/lib/dates';
import { t } from '@/lib/translate';
import type { TranslationKey } from '@/locales/en';
import type { GitSource, GitSourceSyncStatus } from '@/types/git-source';

const props = defineProps<{
    source: GitSource;
    active: boolean;
    starting: boolean;
    detailError: string;
    syncError: string;
}>();
defineEmits<{ sync: []; refresh: [] }>();

const provider = computed(() => getGitProvider(props.source.provider));
const statusLabels: Record<GitSourceSyncStatus, TranslationKey> = {
    idle: 'sync.idle',
    queued: 'sync.queued',
    syncing: 'sync.syncing',
    waiting: 'sync.waiting',
    succeeded: 'sync.succeeded',
    failed: 'sync.failed',
};
const buttonLabel = computed(() => {
    if (props.starting) return t('sync.starting');
    if (props.source.sync_status === 'waiting') return t('sync.waitingAction');
    return t(props.active ? 'sync.inProgress' : 'sync.start');
});
</script>

<template>
    <section
        class="mx-auto flex w-full max-w-lg flex-col items-center gap-5 text-center"
    >
        <div
            class="flex size-20 items-center justify-center rounded-3xl border border-line/70 bg-surface shadow-sm"
        >
            <GitSourceAvatar
                :provider="source.provider"
                :url="source.avatar_url"
                :size="40"
            />
        </div>
        <div class="flex w-full min-w-0 flex-col gap-2">
            <h1
                class="text-2xl leading-tight font-semibold tracking-tight wrap-anywhere sm:text-3xl"
            >
                {{ source.name }}
            </h1>
            <p class="text-sm text-muted">
                {{ source.account }} · {{ provider.name }}
            </p>
        </div>
        <div
            class="flex w-full flex-col gap-5 rounded-2xl border border-line bg-surface p-5 text-left text-sm"
        >
            <dl class="flex flex-col gap-4">
                <div class="flex flex-col gap-1">
                    <dt class="text-xs text-muted">
                        {{ t('source.profile') }}
                    </dt>
                    <dd>
                        <a
                            :href="source.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="focus-ring rounded-sm wrap-anywhere text-accent underline-offset-4 hover:underline"
                        >
                            {{ source.url }}
                        </a>
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-muted">{{ t('source.accountType') }}</dt>
                    <dd>
                        {{
                            t(
                                source.account_type === 'organization'
                                    ? 'source.organization'
                                    : 'source.user',
                            )
                        }}
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-muted">{{ t('source.lastSync') }}</dt>
                    <dd class="text-right">
                        <time
                            v-if="source.last_synced_at !== null"
                            :datetime="isoDateString(source.last_synced_at)"
                            >{{ dateString(source.last_synced_at) }}</time
                        >
                        <span v-else>{{ t('source.neverSynced') }}</span>
                    </dd>
                </div>
            </dl>
            <div class="flex flex-col gap-3 border-t border-line pt-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-muted">{{ t('sync.status') }}</span>
                    <span
                        class="flex items-center gap-2 font-medium"
                        role="status"
                    >
                        <PhCircleNotch
                            v-if="
                                source.sync_status === 'queued' ||
                                source.sync_status === 'syncing'
                            "
                            :size="16"
                            class="animate-spin text-accent motion-reduce:animate-none"
                            aria-hidden="true"
                        />
                        <PhClock
                            v-else-if="source.sync_status === 'waiting'"
                            :size="16"
                            class="text-muted"
                            aria-hidden="true"
                        />
                        <PhWarningCircle
                            v-else-if="source.sync_status === 'failed'"
                            :size="16"
                            class="text-danger"
                            aria-hidden="true"
                        />
                        {{ t(statusLabels[source.sync_status]) }}
                    </span>
                </div>
                <p v-if="active" class="text-xs leading-relaxed text-muted">
                    {{
                        t(
                            source.sync_status === 'waiting'
                                ? 'sync.waitingHint'
                                : 'sync.activeHint',
                        )
                    }}
                </p>
                <p
                    v-if="
                        source.sync_status === 'waiting' &&
                        source.sync_retry_at !== null
                    "
                    class="flex flex-wrap justify-between gap-2 text-xs"
                >
                    <span class="text-muted">{{ t('sync.retryAt') }}</span>
                    <time :datetime="isoDateString(source.sync_retry_at)">{{
                        dateTimeString(source.sync_retry_at)
                    }}</time>
                </p>
                <button
                    type="button"
                    class="secondary-button focus-ring gap-2 disabled:cursor-default disabled:opacity-60"
                    :disabled="active || starting"
                    @click="$emit('sync')"
                >
                    <PhArrowsClockwise :size="17" aria-hidden="true" />
                    {{ buttonLabel }}
                </button>
                <p v-if="syncError" class="text-sm text-danger" role="alert">
                    {{ syncError }}
                </p>
                <div
                    v-if="detailError"
                    class="flex flex-col items-start gap-2"
                    role="status"
                >
                    <p class="text-xs leading-relaxed text-muted">
                        {{ detailError }}
                    </p>
                    <button
                        type="button"
                        class="focus-ring rounded-sm text-xs font-medium text-accent underline-offset-4 hover:underline"
                        @click="$emit('refresh')"
                    >
                        {{ t('sync.refresh') }}
                    </button>
                </div>
            </div>
        </div>
        <div
            v-if="source.last_sync_error_code"
            class="flex w-full gap-3 rounded-2xl border border-danger/35 bg-surface p-4 text-left"
            role="status"
        >
            <PhWarningCircle
                :size="20"
                class="shrink-0 text-danger"
                aria-hidden="true"
            />
            <div class="flex min-w-0 flex-col gap-1.5">
                <p class="text-sm font-medium">{{ t('sync.lastError') }}</p>
                <p class="text-sm leading-relaxed text-muted">
                    {{ errorMessage(source.last_sync_error_code) }}
                </p>
                <time
                    v-if="source.last_sync_error_at !== null"
                    :datetime="isoDateString(source.last_sync_error_at)"
                    class="text-xs text-muted"
                    >{{ dateTimeString(source.last_sync_error_at) }}</time
                >
            </div>
        </div>
    </section>
</template>
