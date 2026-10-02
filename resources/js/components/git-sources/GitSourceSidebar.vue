<script setup lang="ts">
import { PhMagnifyingGlass, PhPlus, PhX } from '@phosphor-icons/vue';
import { computed, useId, useTemplateRef } from 'vue';
import GitSourceItem from '@/components/git-sources/GitSourceItem.vue';
import { t } from '@/lib/translate';
import type { GitSource } from '@/types/git-source';

const props = withDefaults(
    defineProps<{
        sources: readonly GitSource[];
        selectedId: string | null;
        mobile?: boolean;
        headingId?: string;
    }>(),
    { mobile: false },
);

const query = defineModel<string>('query', { required: true });
defineEmits<{ select: [id: string]; create: []; close: [] }>();
const searchId = useId();
const searchInput = useTemplateRef<HTMLInputElement>('searchInput');
const createButton = useTemplateRef<HTMLButtonElement>('createButton');

defineExpose({ focusCreate: () => createButton.value?.focus() });

const filteredSources = computed(() => {
    const search = query.value.trim().toLowerCase();
    return props.sources.filter((source) =>
        `${source.account} ${source.provider.name}`
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
            class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 pb-4"
        >
            <ul v-if="filteredSources.length" class="flex flex-col gap-1">
                <li v-for="source in filteredSources" :key="source.id">
                    <GitSourceItem
                        :provider="source.provider"
                        :account="source.account"
                        :selected="source.id === selectedId"
                        @select="$emit('select', source.id)"
                    />
                </li>
            </ul>
            <div
                v-else
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
            class="flex flex-col gap-3 border-t border-line p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))]"
        >
            <p class="text-xs text-muted" aria-live="polite">
                {{
                    t(
                        filteredSources.length === 1
                            ? 'sidebar.countOne'
                            : 'sidebar.count',
                        { count: filteredSources.length },
                    )
                }}
            </p>
            <button
                ref="createButton"
                type="button"
                class="primary-button focus-ring w-full"
                @click="$emit('create')"
            >
                <PhPlus :size="17" weight="bold" aria-hidden="true" />
                {{ t('sidebar.create') }}
            </button>
        </div>
    </aside>
</template>
