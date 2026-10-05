<script setup lang="ts">
import { PhCaretDown, PhMagnifyingGlass, PhX } from '@phosphor-icons/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useId,
    useTemplateRef,
} from 'vue';
import { t } from '@/lib/translate';
import type { TranslationKey } from '@/locales/en';
import type {
    RepositoryFilters as RepositoryFilterValues,
    RepositorySort,
} from '@/types/remote-repository';

defineProps<{ languages: readonly (string | null)[] }>();
const filters = defineModel<RepositoryFilterValues>('filters', {
    required: true,
});
const id = useId();
const searchInput = useTemplateRef<HTMLInputElement>('searchInput');
const languageArea = useTemplateRef<HTMLElement>('languageArea');
const languageTrigger = useTemplateRef<HTMLButtonElement>('languageTrigger');
const languagePanel = useTemplateRef<HTMLElement>('languagePanel');
const languagesOpen = ref(false);
const selectedCount = computed(
    () =>
        filters.value.languages.length + Number(filters.value.without_language),
);
const changed = computed(
    () =>
        filters.value.search !== '' ||
        selectedCount.value > 0 ||
        filters.value.sort !== 'name' ||
        filters.value.direction !== 'asc',
);
const search = computed({
    get: () => filters.value.search,
    set: (search: string) => {
        filters.value = { ...filters.value, search };
    },
});
const sort = computed({
    get: () => filters.value.sort,
    set: (sort: RepositorySort) => {
        filters.value = { ...filters.value, sort };
    },
});
const direction = computed({
    get: () => filters.value.direction,
    set: (direction: 'asc' | 'desc') => {
        filters.value = { ...filters.value, direction };
    },
});
const sortOptions: { value: RepositorySort; label: TranslationKey }[] = [
    { value: 'name', label: 'repositories.sortName' },
    { value: 'issues_count', label: 'repository.issues' },
    { value: 'pull_requests_count', label: 'repository.pullRequests' },
    { value: 'last_committed_at', label: 'repository.lastCommit' },
    { value: 'stars_count', label: 'repository.stars' },
    { value: 'forks_count', label: 'repository.forks' },
];

function clearSearch() {
    search.value = '';
    searchInput.value?.focus();
}

function reset() {
    filters.value = {
        search: '',
        languages: [],
        without_language: false,
        sort: 'name',
        direction: 'asc',
    };
    searchInput.value?.focus();
}

function selectLanguage(language: string | null, event: Event) {
    const checked = (event.target as HTMLInputElement).checked;
    filters.value =
        language === null
            ? { ...filters.value, without_language: checked }
            : {
                  ...filters.value,
                  languages: checked
                      ? [...filters.value.languages, language]
                      : filters.value.languages.filter(
                            (item) => item !== language,
                        ),
              };
}

async function toggleLanguages() {
    languagesOpen.value = !languagesOpen.value;
    if (languagesOpen.value) {
        await nextTick();
        languagePanel.value?.querySelector<HTMLInputElement>('input')?.focus();
    }
}

function closeOnEscape(event: KeyboardEvent) {
    if (!languagesOpen.value) return;
    event.preventDefault();
    event.stopPropagation();
    languagesOpen.value = false;
    languageTrigger.value?.focus();
}

function closeOutside(event: PointerEvent) {
    if (
        event.target instanceof Node &&
        !languageArea.value?.contains(event.target)
    ) {
        languagesOpen.value = false;
    }
}

function closeOnFocusLeave(event: FocusEvent) {
    if (
        !(event.relatedTarget instanceof Node) ||
        !languageArea.value?.contains(event.relatedTarget)
    ) {
        languagesOpen.value = false;
    }
}

onMounted(() => document.addEventListener('pointerdown', closeOutside));
onBeforeUnmount(() =>
    document.removeEventListener('pointerdown', closeOutside),
);
</script>

<template>
    <div
        role="search"
        :aria-label="t('repositories.filters')"
        class="flex flex-col gap-3"
    >
        <div
            class="grid grid-cols-1 items-end gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_10rem_11rem_9rem]"
        >
            <div class="flex min-w-0 flex-col gap-2">
                <label :for="`${id}-search`" class="text-sm font-medium">{{
                    t('repositories.search')
                }}</label>
                <div class="relative">
                    <PhMagnifyingGlass
                        :size="17"
                        class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted"
                        aria-hidden="true"
                    />
                    <input
                        :id="`${id}-search`"
                        ref="searchInput"
                        v-model="search"
                        type="search"
                        maxlength="255"
                        :placeholder="t('repositories.searchPlaceholder')"
                        autocomplete="off"
                        spellcheck="false"
                        class="text-input min-h-11 py-2.5 pr-10 pl-9 md:text-sm [&::-webkit-search-cancel-button]:appearance-none"
                    />
                    <button
                        v-if="search"
                        type="button"
                        class="focus-ring absolute top-1/2 right-1 flex size-9 -translate-y-1/2 items-center justify-center rounded-lg text-muted hover:text-foreground"
                        :aria-label="t('repositories.clearSearch')"
                        @click="clearSearch"
                    >
                        <PhX :size="15" aria-hidden="true" />
                    </button>
                </div>
            </div>
            <div
                ref="languageArea"
                class="relative flex min-w-0 flex-col gap-2"
                @keydown.esc="closeOnEscape"
                @focusout="closeOnFocusLeave"
            >
                <span
                    :id="`${id}-languages-label`"
                    class="text-sm font-medium"
                    >{{ t('repositories.languages') }}</span
                >
                <button
                    ref="languageTrigger"
                    type="button"
                    class="secondary-button focus-ring w-full justify-between gap-2 px-3.5"
                    :aria-expanded="languagesOpen"
                    :aria-controls="`${id}-languages`"
                    :aria-labelledby="`${id}-languages-label ${id}-languages-value`"
                    @click="toggleLanguages"
                >
                    <span :id="`${id}-languages-value`" class="truncate">{{
                        selectedCount
                            ? t('repositories.languagesSelected', {
                                  count: selectedCount,
                              })
                            : t('repositories.allLanguages')
                    }}</span>
                    <PhCaretDown
                        :size="15"
                        class="shrink-0"
                        aria-hidden="true"
                    />
                </button>
                <div
                    v-show="languagesOpen"
                    :id="`${id}-languages`"
                    ref="languagePanel"
                    role="group"
                    :aria-labelledby="`${id}-languages-label`"
                    class="absolute top-full left-0 z-20 mt-2 w-full min-w-56 overflow-hidden rounded-xl border border-line bg-surface shadow-lg sm:right-0 sm:left-auto"
                >
                    <div
                        v-if="languages.length"
                        class="max-h-64 overflow-y-auto overscroll-contain p-1.5"
                    >
                        <label
                            v-for="language in languages"
                            :key="language ?? 'no-language'"
                            class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm hover:bg-hover"
                        >
                            <input
                                type="checkbox"
                                :checked="
                                    language === null
                                        ? filters.without_language
                                        : filters.languages.includes(language)
                                "
                                class="focus-ring size-4 shrink-0 rounded-sm accent-accent"
                                @change="selectLanguage(language, $event)"
                            />
                            <span class="wrap-anywhere">{{
                                language ?? t('repository.noLanguage')
                            }}</span>
                        </label>
                    </div>
                    <p v-else class="px-4 py-3 text-sm text-muted">
                        {{ t('repositories.noLanguages') }}
                    </p>
                    <p
                        class="border-t border-line px-4 py-3 text-xs leading-relaxed text-muted"
                    >
                        {{ t('repositories.languagesHint') }}
                    </p>
                </div>
            </div>
            <div class="flex min-w-0 flex-col gap-2">
                <label :for="`${id}-sort`" class="text-sm font-medium">{{
                    t('repositories.sort')
                }}</label>
                <div class="relative">
                    <select
                        :id="`${id}-sort`"
                        v-model="sort"
                        class="text-input min-h-11 appearance-none py-2.5 pr-9 md:text-sm"
                    >
                        <option
                            v-for="option in sortOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ t(option.label) }}
                        </option>
                    </select>
                    <PhCaretDown
                        :size="15"
                        class="pointer-events-none absolute top-1/2 right-3.5 -translate-y-1/2 text-muted"
                        aria-hidden="true"
                    />
                </div>
            </div>
            <div class="flex min-w-0 flex-col gap-2">
                <label :for="`${id}-direction`" class="text-sm font-medium">{{
                    t('repositories.direction')
                }}</label>
                <div class="relative">
                    <select
                        :id="`${id}-direction`"
                        v-model="direction"
                        class="text-input min-h-11 appearance-none py-2.5 pr-9 md:text-sm"
                    >
                        <option value="asc">
                            {{
                                t(
                                    sort === 'last_committed_at'
                                        ? 'repositories.oldestFirst'
                                        : 'repositories.ascending',
                                )
                            }}
                        </option>
                        <option value="desc">
                            {{
                                t(
                                    sort === 'last_committed_at'
                                        ? 'repositories.newestFirst'
                                        : 'repositories.descending',
                                )
                            }}
                        </option>
                    </select>
                    <PhCaretDown
                        :size="15"
                        class="pointer-events-none absolute top-1/2 right-3.5 -translate-y-1/2 text-muted"
                        aria-hidden="true"
                    />
                </div>
            </div>
        </div>
        <button
            v-if="changed"
            type="button"
            class="focus-ring flex min-h-8 w-fit items-center gap-1.5 rounded-md text-sm text-muted hover:text-foreground"
            @click="reset"
        >
            <PhX :size="15" aria-hidden="true" />
            {{ t('repositories.resetFilters') }}
        </button>
    </div>
</template>
