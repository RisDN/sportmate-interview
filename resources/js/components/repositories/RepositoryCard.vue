<script setup lang="ts">
import {
    PhArchive,
    PhArrowSquareOut,
    PhCode,
    PhGitCommit,
    PhGitFork,
    PhGitPullRequest,
    PhSealWarning,
    PhStar,
} from '@phosphor-icons/vue';
import { computed } from 'vue';
import { dateString, isoDateString } from '@/lib/dates';
import { t } from '@/lib/translate';
import type { RemoteRepository } from '@/types/remote-repository';

const props = defineProps<{ repository: RemoteRepository }>();
const numberFormat = new Intl.NumberFormat('en');
const statistics = computed(() => [
    {
        label: t('repository.stars'),
        value: props.repository.stars_count,
        icon: PhStar,
    },
    {
        label: t('repository.forks'),
        value: props.repository.forks_count,
        icon: PhGitFork,
    },
    {
        label: t('repository.issues'),
        value: props.repository.issues_count,
        icon: PhSealWarning,
    },
    {
        label: t('repository.pullRequests'),
        value: props.repository.pull_requests_count,
        icon: PhGitPullRequest,
    },
]);
</script>

<template>
    <article
        :class="[
            'flex h-full min-w-0 flex-col gap-5 rounded-2xl border bg-surface p-5 sm:p-6',
            repository.archived ? 'border-archive-line' : 'border-line',
        ]"
    >
        <div class="flex items-start gap-3">
            <div class="flex min-w-0 flex-1 flex-col gap-2.5">
                <h3
                    class="text-lg leading-snug font-semibold tracking-tight wrap-anywhere"
                >
                    {{ repository.name }}
                </h3>
                <span
                    v-if="repository.archived"
                    class="inline-flex w-fit items-center gap-1.5 rounded-md border border-archive-line/60 bg-archive-surface px-2 py-0.5 text-xs font-medium text-archive"
                >
                    <PhArchive :size="13" aria-hidden="true" />
                    {{ t('repository.archived') }}
                </span>
            </div>
            <a
                :href="repository.url"
                target="_blank"
                rel="noopener noreferrer"
                class="icon-button focus-ring -mt-1 -mr-1 active:scale-[0.98] motion-reduce:transform-none"
                :aria-label="t('repository.open', { name: repository.name })"
                :title="t('repository.open', { name: repository.name })"
            >
                <PhArrowSquareOut :size="20" aria-hidden="true" />
            </a>
        </div>
        <p class="text-sm leading-relaxed wrap-anywhere text-muted">
            {{ repository.description || t('repository.noDescription') }}
        </p>
        <dl
            class="mt-auto grid grid-cols-2 gap-x-5 gap-y-3 border-t border-line pt-4"
        >
            <div
                v-for="statistic in statistics"
                :key="statistic.label"
                class="flex min-w-0 flex-col gap-0.5"
            >
                <dt
                    class="flex items-start gap-2 text-xs leading-relaxed text-muted"
                >
                    <component
                        :is="statistic.icon"
                        :size="17"
                        class="shrink-0"
                        aria-hidden="true"
                    />
                    {{ statistic.label }}
                </dt>
                <dd class="pl-6 text-sm font-medium tabular-nums">
                    {{ numberFormat.format(statistic.value) }}
                </dd>
            </div>
        </dl>
        <dl class="flex flex-wrap gap-x-6 gap-y-3 text-xs text-muted">
            <div class="flex min-w-0 flex-col gap-1">
                <dt class="flex items-center gap-2">
                    <PhCode :size="16" class="shrink-0" aria-hidden="true" />
                    {{ t('repository.language') }}
                </dt>
                <dd class="pl-6 wrap-anywhere text-foreground">
                    {{ repository.language || t('repository.noLanguage') }}
                </dd>
            </div>
            <div class="flex flex-col gap-1">
                <dt class="flex items-center gap-2">
                    <PhGitCommit
                        :size="16"
                        class="shrink-0"
                        aria-hidden="true"
                    />
                    {{ t('repository.lastCommit') }}
                </dt>
                <dd class="pl-6 text-foreground">
                    <time
                        v-if="repository.last_committed_at !== null"
                        :datetime="isoDateString(repository.last_committed_at)"
                        >{{ dateString(repository.last_committed_at) }}</time
                    >
                    <span v-else>{{ t('repository.noCommits') }}</span>
                </dd>
            </div>
        </dl>
    </article>
</template>
