import { useHttp } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { show } from '@/actions/App/Http/Controllers/GitSourceController';
import { store } from '@/actions/App/Http/Controllers/GitSourceSyncController';
import { apiErrorMessage, isCancelledRequest } from '@/lib/api-errors';
import { t } from '@/lib/translate';
import type { GitSource, GitSourceResponse } from '@/types/git-source';
import type {
    RepositoryObservation,
    RepositorySnapshot,
} from '@/types/remote-repository';

export function useGitSourceSync(
    selectedSource: Ref<GitSource | null>,
    reconcile: (source: GitSource) => void,
    repositoryObservation: Ref<RepositoryObservation | null>,
    reconcileRepositories: (
        snapshot: RepositorySnapshot,
        observed: RepositoryObservation,
    ) => void,
    removeSource: (source: GitSource) => void,
) {
    const starting = ref(false);
    const detailError = ref('');
    const syncError = ref('');
    const detailRequest = useHttp<Record<string, never>, GitSourceResponse>({});
    const syncRequest = useHttp<Record<string, never>, GitSourceResponse>({});
    const active = computed(() =>
        ['queued', 'syncing', 'waiting'].includes(
            selectedSource.value?.sync_status ?? '',
        ),
    );
    let generation = 0;
    let inFlight = false;
    let refreshPending = false;
    let mounted = false;
    let timer: ReturnType<typeof setTimeout> | undefined;

    function clearTimer() {
        if (timer !== undefined) clearTimeout(timer);
        timer = undefined;
    }

    function schedule() {
        clearTimer();
        if (!mounted || document.hidden || !active.value || starting.value)
            return;
        const delay =
            selectedSource.value?.sync_status === 'waiting' ? 30_000 : 5_000;
        timer = setTimeout(() => void refresh(), delay);
    }

    function applySource(source: GitSource) {
        if (selectedSource.value?.id !== source.id) return;
        reconcile(source);
    }

    async function refresh() {
        const id = selectedSource.value?.id;
        if (!mounted || document.hidden || !id || starting.value) return;
        if (inFlight) {
            refreshPending = true;
            return;
        }
        clearTimer();
        const currentGeneration = generation;
        const observed = repositoryObservation.value;
        inFlight = true;
        refreshPending = false;

        function isCurrent() {
            return (
                currentGeneration === generation &&
                selectedSource.value?.id === id
            );
        }

        try {
            await detailRequest.get(
                show.url(
                    { gitSource: Number(id) },
                    {
                        query: observed
                            ? {
                                  repository_page: observed.page,
                                  ...observed.filters,
                              }
                            : undefined,
                    },
                ),
                {
                    onSuccess(response) {
                        if (!isCurrent()) return;
                        detailError.value = '';
                        applySource(response.data);
                        if (observed && response.repositories) {
                            reconcileRepositories(
                                response.repositories,
                                observed,
                            );
                        }
                    },
                    onError() {
                        if (isCurrent())
                            detailError.value = t('sync.statusFailed');
                    },
                    onHttpException(response) {
                        if (
                            response.status === 404 &&
                            isCurrent() &&
                            selectedSource.value
                        )
                            removeSource(selectedSource.value);
                    },
                },
            );
        } catch (failure) {
            if (isCurrent() && !isCancelledRequest(failure)) {
                detailError.value = apiErrorMessage(
                    failure,
                    'sync.statusFailed',
                );
            }
        } finally {
            if (isCurrent()) {
                inFlight = false;
                if (refreshPending) void refresh();
                else schedule();
            }
        }
    }

    async function startSync() {
        const id = selectedSource.value?.id;
        if (!id || starting.value || active.value) return;
        clearTimer();
        const currentGeneration = ++generation;
        detailRequest.cancel();
        inFlight = false;
        refreshPending = false;
        starting.value = true;
        syncError.value = '';

        function isCurrent() {
            return (
                currentGeneration === generation &&
                selectedSource.value?.id === id
            );
        }

        try {
            await syncRequest.post(store.url({ gitSource: Number(id) }), {
                onSuccess(response) {
                    if (isCurrent()) applySource(response.data);
                },
                onError() {
                    if (isCurrent()) syncError.value = t('sync.startFailed');
                },
                onHttpException(response) {
                    if (
                        response.status === 404 &&
                        isCurrent() &&
                        selectedSource.value
                    )
                        removeSource(selectedSource.value);
                },
            });
        } catch (failure) {
            if (isCurrent() && !isCancelledRequest(failure)) {
                syncError.value = apiErrorMessage(failure, 'sync.startFailed');
            }
        } finally {
            if (isCurrent()) {
                starting.value = false;
                void refresh();
            }
        }
    }

    function refreshWhenVisible() {
        if (document.hidden) clearTimer();
        else void refresh();
    }

    watch(
        () => selectedSource.value?.id,
        () => {
            generation++;
            clearTimer();
            detailRequest.cancel();
            syncRequest.cancel();
            inFlight = false;
            refreshPending = false;
            starting.value = false;
            detailError.value = '';
            syncError.value = '';
            void refresh();
        },
    );

    watch(repositoryObservation, (observed) => {
        if (observed) void refresh();
    });

    onMounted(() => {
        mounted = true;
        document.addEventListener('visibilitychange', refreshWhenVisible);
        window.addEventListener('focus', refreshWhenVisible);
        void refresh();
    });

    onBeforeUnmount(() => {
        mounted = false;
        generation++;
        clearTimer();
        detailRequest.cancel();
        syncRequest.cancel();
        document.removeEventListener('visibilitychange', refreshWhenVisible);
        window.removeEventListener('focus', refreshWhenVisible);
    });

    return { starting, active, detailError, syncError, refresh, startSync };
}
