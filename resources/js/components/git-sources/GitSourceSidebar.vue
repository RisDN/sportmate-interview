<script setup lang="ts">
import {
    PhCaretLeft,
    PhCaretRight,
    PhCircleNotch,
    PhMagnifyingGlass,
    PhPlus,
    PhX,
} from '@phosphor-icons/vue';
import { computed, useId, useTemplateRef } from 'vue';
import GitSourceItem from '@/components/git-sources/GitSourceItem.vue';
import { getGitProvider } from '@/data/git-providers';
import { t } from '@/lib/translate';
import type { GitSource, GitSourcePagination } from '@/types/git-source';

const props = withDefaults(
    defineProps<{
        sources: readonly GitSource[];
        selectedId: string | null;
        pagination: GitSourcePagination | null;
        loading: boolean;
        failed: boolean;
        mobile?: boolean;
        headingId?: string;
    }>(),
    { mobile: false },
);

const query = defineModel<string>('query', { required: true });
defineEmits<{
    select: [id: string];
    create: [];
    close: [];
    page: [page: number];
    retry: [];
}>();
const searchId = useId();
const searchInput = useTemplateRef<HTMLInputElement>('searchInput');
const createButton = useTemplateRef<HTMLButtonElement>('createButton');

defineExpose({ focusCreate: () => createButton.value?.focus() });

const filteredSources = computed(() => {
    const search = query.value.trim().toLowerCase();
    return props.sources.filter((source) =>
        `${source.name} ${source.account} ${getGitProvider(source.provider).name}`
            .toLowerCase()
            .includes(search),
    );
});

function clearSearch() {
    query.value = '';
    searchInput.value?.focus();
}
</script>

<template>
    <aside class="flex h-full min-h-0 flex-col bg-sidebar">
        <div class="flex flex-col gap-5 px-5 pt-6 pb-5">
            <div class="flex min-h-10 items-center justify-between gap-3">
                <h2
                    :id="headingId"
                    class="text-lg font-semibold tracking-tight"
                >
                    {{ t('sidebar.title') }}
                </h2>
                <button
                    v-if="mobile"
                    type="button"
                    class="icon-button focus-ring -mr-2"
                    :aria-label="t('sidebar.close')"
                    @click="$emit('close')"
                >
                    <PhX :size="19" aria-hidden="true" />
                </button>
            </div>
            <div role="search" class="relative">
                <label :for="searchId" class="sr-only">{{
                    t('sidebar.searchLabel')
                }}</label>
                <PhMagnifyingGlass
                    :size="17"
                    class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted"
                    aria-hidden="true"
                />
                <input
                    :id="searchId"
                    ref="searchInput"
                    v-model="query"
                    type="search"
                    :placeholder="t('sidebar.search')"
                    autocomplete="off"
                    spellcheck="false"
                    class="text-input py-2.5 pr-10 pl-9 md:text-sm [&::-webkit-search-cancel-button]:appearance-none"
                />
                <button
                    v-if="query"
                    type="button"
                    class="focus-ring absolute top-1/2 right-1 flex size-9 -translate-y-1/2 items-center justify-center rounded-lg text-muted hover:text-foreground"
                    :aria-label="t('sidebar.clearSearch')"
                    @click="clearSearch"
                >
                    <PhX :size="15" aria-hidden="true" />
                </button>
            </div>
        </div>

        <nav
            :aria-label="t('sidebar.listLabel')"
            :aria-busy="loading"
            class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 pb-4"
        >
            <div
                v-if="loading"
                class="flex items-center justify-center gap-2 py-8 text-sm text-muted"
                role="status"
            >
                <PhCircleNotch
                    :size="20"
                    class="animate-spin motion-reduce:animate-none"
                    aria-hidden="true"
                />
                {{ t('sources.loading') }}
            </div>
            <div
                v-if="failed"
                class="flex flex-col items-center gap-3 px-4 py-5"
                role="status"
            >
                <p class="text-center text-sm text-muted">
                    {{ t('sources.loadFailed') }}
                </p>
                <button
                    type="button"
                    class="secondary-button focus-ring"
                    @click="$emit('retry')"
                >
                    {{ t('sources.retry') }}
                </button>
            </div>
            <ul
                v-if="!loading && filteredSources.length"
                class="flex flex-col gap-1"
            >
                <li v-for="source in filteredSources" :key="source.id">
                    <GitSourceItem
                        :source="source"
                        :selected="source.id === selectedId"
                        @select="$emit('select', source.id)"
                    />
                </li>
            </ul>
            <div
                v-else-if="!loading && !failed"
                class="flex flex-col gap-2 px-4 py-8 text-center"
                role="status"
            >
                <p class="text-sm font-medium">
                    {{
                        t(
                            sources.length
                                ? 'sidebar.noResults'
                                : 'sidebar.empty',
                        )
                    }}
                </p>
                <p class="text-xs leading-relaxed text-muted">
                    {{
                        t(
                            sources.length
                                ? 'sidebar.noResultsHint'
                                : 'sidebar.emptyHint',
                        )
                    }}
                </p>
            </div>
        </nav>

        <div
            class="flex flex-col gap-3 p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))]"
        >
            <div v-if="pagination" class="flex flex-col gap-2">
                <p class="text-center text-xs text-muted" role="status">
                    {{
                        t('sidebar.count', {
                            count: filteredSources.length,
                            total: pagination.total,
                        })
                    }}
                </p>
                <nav
                    v-if="pagination.last_page > 1"
                    class="flex items-center justify-between gap-2"
                    :aria-label="t('sidebar.pagination')"
                >
                    <button
                        type="button"
                        class="icon-button focus-ring disabled:cursor-default disabled:opacity-40"
                        :disabled="loading || pagination.current_page <= 1"
                        :aria-label="t('sidebar.previous')"
                        @click="$emit('page', pagination.current_page - 1)"
                    >
                        <PhCaretLeft :size="18" aria-hidden="true" />
                    </button>
                    <span class="text-xs text-muted">{{
                        t('sidebar.page', {
                            page: pagination.current_page,
                            pages: pagination.last_page,
                        })
                    }}</span>
                    <button
                        type="button"
                        class="icon-button focus-ring disabled:cursor-default disabled:opacity-40"
                        :disabled="
                            loading ||
                            pagination.current_page >= pagination.last_page
                        "
                        :aria-label="t('sidebar.next')"
                        @click="$emit('page', pagination.current_page + 1)"
                    >
                        <PhCaretRight :size="18" aria-hidden="true" />
                    </button>
                </nav>
            </div>
            <button
                ref="createButton"
                type="button"
                class="primary-button focus-ring w-full"
                @click="$emit('create')"
            >
                <PhPlus :size="17" weight="bold" aria-hidden="true" />
                {{ t('sidebar.addSource') }}
            </button>
        </div>
    </aside>
</template>
