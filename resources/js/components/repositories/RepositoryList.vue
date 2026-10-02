<script setup lang="ts">
import { PhGitBranch } from '@phosphor-icons/vue';
import { computed, useTemplateRef } from 'vue';
import RepositoryCard from '@/components/repositories/RepositoryCard.vue';
import AppPagination from '@/components/ui/AppPagination.vue';
import { t } from '@/lib/translate';
import type { GitSourceSyncStatus } from '@/types/git-source';
import type { PaginationMeta } from '@/types/pagination';
import type { RemoteRepository } from '@/types/remote-repository';

const props = defineProps<{
    repositories: readonly RemoteRepository[];
    pagination: PaginationMeta | null;
    loading: boolean;
    refreshing: boolean;
    error: string;
    syncStatus: GitSourceSyncStatus;
    syncActive: boolean;
}>();
defineEmits<{ page: [page: number]; retry: [] }>();
const heading = useTemplateRef<HTMLHeadingElement>('heading');

defineExpose({
    focusHeading() {
        heading.value?.focus({ preventScroll: true });
        heading.value?.scrollIntoView({
            block: 'start',
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)')
                .matches
                ? 'instant'
                : 'smooth',
        });
    },
});

const emptyTitle = computed(() => {
    if (props.syncActive) return t('repositories.pending');
    return t(
        props.syncStatus === 'succeeded'
            ? 'repositories.emptySynced'
            : 'repositories.empty',
    );
});
const emptyHint = computed(() => {
    if (props.syncActive) return t('repositories.pendingHint');
    return t(
        props.syncStatus === 'succeeded'
            ? 'repositories.emptySyncedHint'
            : 'repositories.emptyHint',
    );
});
</script>

<template>
    <section
        class="flex w-full flex-col gap-5"
        aria-labelledby="repositories-title"
        :aria-busy="loading || refreshing"
    >
        <div class="flex flex-col gap-2">
            <h2
                id="repositories-title"
                ref="heading"
                tabindex="-1"
                class="focus-ring scroll-mt-4 rounded-sm text-xl font-semibold tracking-tight"
            >
                {{ t('repositories.title') }}
            </h2>
            <p v-if="pagination" class="text-sm text-muted" role="status">
                {{
                    t('repositories.count', {
                        count: repositories.length,
                        total: pagination.total,
                    })
                }}
            </p>
            <p v-if="syncActive" class="text-xs text-muted">
                {{ t('repositories.progress') }}
            </p>
        </div>
        <div
            v-if="error"
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-danger/35 bg-surface p-4"
            role="status"
        >
            <p class="text-sm text-muted">{{ error }}</p>
            <button
                type="button"
                class="secondary-button focus-ring"
                :disabled="loading"
                @click="$emit('retry')"
            >
                {{ t('repositories.retry') }}
            </button>
        </div>
        <div v-if="loading" role="status">
            <span class="sr-only">{{ t('repositories.loading') }}</span>
            <div
                class="grid grid-cols-1 gap-4 xl:grid-cols-2"
                aria-hidden="true"
            >
                <div
                    v-for="item in 4"
                    :key="item"
                    class="flex flex-col gap-5 rounded-2xl border border-line bg-surface p-6 motion-safe:animate-pulse"
                >
                    <div class="h-5 w-3/5 rounded-md bg-hover"></div>
                    <div class="flex flex-col gap-2">
                        <div class="h-3 w-full rounded-md bg-hover"></div>
                        <div class="h-3 w-4/5 rounded-md bg-hover"></div>
                    </div>
                    <div
                        class="grid grid-cols-2 gap-4 border-t border-line pt-4"
                    >
                        <div
                            v-for="statistic in 4"
                            :key="statistic"
                            class="h-9 w-4/5 rounded-md bg-hover"
                        ></div>
                    </div>
                </div>
            </div>
        </div>
        <ul
            v-else-if="repositories.length"
            class="grid grid-cols-1 gap-4 xl:grid-cols-2"
        >
            <li
                v-for="repository in repositories"
                :key="repository.external_id"
                class="min-w-0"
            >
                <RepositoryCard :repository="repository" />
            </li>
        </ul>
        <div
            v-else-if="!error"
            class="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-line px-6 py-12 text-center"
            role="status"
        >
            <PhGitBranch :size="28" class="text-muted" aria-hidden="true" />
            <h3 class="text-sm font-semibold">{{ emptyTitle }}</h3>
            <p class="max-w-sm text-sm leading-relaxed text-muted">
                {{ emptyHint }}
            </p>
        </div>
        <AppPagination
            v-if="pagination"
            class="mx-auto w-full max-w-64"
            :pagination="pagination"
            :loading="loading"
            :label="t('repositories.pagination')"
            @page="$emit('page', $event)"
        />
    </section>
</template>
