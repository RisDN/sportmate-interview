import { useHttp } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import type { Ref } from 'vue';
import { index } from '@/actions/App/Http/Controllers/RemoteRepositoryController';
import { apiErrorMessage, isCancelledRequest } from '@/lib/api-errors';
import { t } from '@/lib/translate';
import type { PaginationMeta } from '@/types/pagination';
import type {
    RemoteRepository,
    RemoteRepositoryPage,
    RepositoryFilters,
    RepositoryObservation,
    RepositorySnapshot,
} from '@/types/remote-repository';

function defaultFilters(): RepositoryFilters {
    return {
        search: '',
        languages: [],
        without_language: false,
        sort: 'name',
        direction: 'asc',
    };
}

export function useRemoteRepositories(sourceId: Ref<string | null>) {
    const repositories = shallowRef<RemoteRepository[]>([]);
    const filters = ref<RepositoryFilters>(defaultFilters());
    const languages = shallowRef<(string | null)[]>([]);
    const pagination = shallowRef<PaginationMeta | null>(null);
    const loading = ref(false);
    const refreshing = ref(false);
    const error = ref('');
    const observation = shallowRef<RepositoryObservation | null>(null);
    const request = useHttp<Record<string, never>, RemoteRepositoryPage>({});
    let generation = 0;
    let requestedPage = 1;
    let inFlight = false;
    let refreshPending = false;
    let disposed = false;
    let resettingFilters = false;
    let filterTimer: ReturnType<typeof setTimeout> | undefined;

    function cancelPending() {
        generation++;
        request.cancel();
        clearTimeout(filterTimer);
        filterTimer = undefined;
        inFlight = false;
        refreshPending = false;
        observation.value = null;
    }

    async function load(page = 1, background = false) {
        const id = sourceId.value;
        if (!id || disposed || filterTimer !== undefined) return;
        if (background && inFlight) {
            refreshPending = true;
            return;
        }

        const currentGeneration = ++generation;
        const appliedFilters: RepositoryFilters = {
            ...filters.value,
            search: filters.value.search.trim(),
            languages: [...filters.value.languages],
        };
        request.cancel();
        requestedPage = page;
        inFlight = true;
        refreshPending = false;
        if (!background) observation.value = null;
        loading.value = !background || pagination.value === null;
        refreshing.value = background && pagination.value !== null;
        if (!background) error.value = '';

        function isCurrent() {
            return currentGeneration === generation && sourceId.value === id;
        }

        try {
            await request.get(
                index.url(
                    { gitSource: Number(id) },
                    { query: { page, ...appliedFilters } },
                ),
                {
                    onSuccess(response) {
                        if (!isCurrent()) return;
                        repositories.value = response.data;
                        pagination.value = response.meta;
                        languages.value = response.languages;
                        requestedPage = response.meta.current_page;
                        observation.value = {
                            sourceId: id,
                            page: response.meta.current_page,
                            fingerprint: response.fingerprint,
                            filters: appliedFilters,
                        };
                        error.value = '';
                    },
                    onError() {
                        if (isCurrent())
                            error.value = t('repositories.loadFailed');
                    },
                },
            );
        } catch (failure) {
            if (isCurrent() && !isCancelledRequest(failure)) {
                error.value = apiErrorMessage(
                    failure,
                    'repositories.loadFailed',
                );
            }
        } finally {
            if (isCurrent()) {
                inFlight = false;
                loading.value = false;
                refreshing.value = false;
                if (refreshPending) void load(requestedPage, true);
            }
        }
    }

    function refresh() {
        void load(requestedPage, true);
    }

    function retry() {
        void load(requestedPage);
    }

    function reconcileSnapshot(
        snapshot: RepositorySnapshot,
        observed: RepositoryObservation,
    ) {
        // A list load or filter/page/source change invalidates earlier snapshots.
        if (
            inFlight ||
            observation.value !== observed ||
            sourceId.value !== observed.sourceId
        )
            return;

        // Off-page repositories can add languages without changing visible rows.
        languages.value = snapshot.languages;
        if (
            snapshot.fingerprint !== observed.fingerprint ||
            snapshot.meta.current_page !== observed.page
        ) {
            void load(snapshot.meta.current_page, true);
        } else {
            pagination.value = snapshot.meta;
        }
    }

    watch(
        filters,
        () => {
            if (resettingFilters || disposed) return;
            cancelPending();
            requestedPage = 1;
            error.value = '';
            refreshing.value = false;
            loading.value = sourceId.value !== null;
            if (!sourceId.value) return;

            filterTimer = setTimeout(() => {
                filterTimer = undefined;
                void load(1);
            }, 400);
        },
        { deep: true, flush: 'sync' },
    );

    watch(
        sourceId,
        () => {
            cancelPending();
            resettingFilters = true;
            filters.value = defaultFilters();
            resettingFilters = false;
            requestedPage = 1;
            repositories.value = [];
            languages.value = [];
            pagination.value = null;
            error.value = '';
            loading.value = false;
            refreshing.value = false;
            if (sourceId.value) void load(1);
        },
        { immediate: true, flush: 'sync' },
    );

    onBeforeUnmount(() => {
        disposed = true;
        cancelPending();
    });

    return {
        repositories,
        filters,
        languages,
        pagination,
        loading,
        refreshing,
        error,
        observation,
        reconcileSnapshot,
        load,
        refresh,
        retry,
    };
}
