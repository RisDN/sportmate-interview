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
} from '@/types/remote-repository';

export function useRemoteRepositories(sourceId: Ref<string | null>) {
    const repositories = shallowRef<RemoteRepository[]>([]);
    const pagination = shallowRef<PaginationMeta | null>(null);
    const loading = ref(false);
    const refreshing = ref(false);
    const error = ref('');
    const request = useHttp<Record<string, never>, RemoteRepositoryPage>({});
    let generation = 0;
    let requestedPage = 1;
    let inFlight = false;
    let refreshPending = false;
    let disposed = false;

    async function load(page = 1, background = false) {
        const id = sourceId.value;
        if (!id || disposed) return;
        if (background && inFlight) {
            refreshPending = true;
            return;
        }

        const currentGeneration = ++generation;
        request.cancel();
        requestedPage = page;
        inFlight = true;
        refreshPending = false;
        loading.value = !background || pagination.value === null;
        refreshing.value = background && pagination.value !== null;
        if (!background) error.value = '';

        function isCurrent() {
            return currentGeneration === generation && sourceId.value === id;
        }

        try {
            await request.get(
                index.url({ gitSource: Number(id) }, { query: { page } }),
                {
                    onSuccess(response) {
                        if (!isCurrent()) return;
                        repositories.value = response.data;
                        pagination.value = response.meta;
                        requestedPage = response.meta.current_page;
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

    watch(
        sourceId,
        () => {
            generation++;
            request.cancel();
            inFlight = false;
            refreshPending = false;
            requestedPage = 1;
            repositories.value = [];
            pagination.value = null;
            error.value = '';
            loading.value = false;
            refreshing.value = false;
            if (sourceId.value) void load(1);
        },
        { immediate: true },
    );

    onBeforeUnmount(() => {
        disposed = true;
        generation++;
        refreshPending = false;
        request.cancel();
    });

    return {
        repositories,
        pagination,
        loading,
        refreshing,
        error,
        load,
        refresh,
        retry,
    };
}
