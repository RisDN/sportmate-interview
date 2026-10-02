import { useHttp } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { index } from '@/actions/App/Http/Controllers/GitSourceController';
import { apiErrorMessage, isCancelledRequest } from '@/lib/api-errors';
import { t } from '@/lib/translate';
import type {
    GitSource,
    GitSourcePage,
    GitSourcePagination,
} from '@/types/git-source';

export function useGitSources(showError: (message: string) => void) {
    const sources = shallowRef<GitSource[]>([]);
    const selectedSource = shallowRef<GitSource | null>(null);
    const pagination = shallowRef<GitSourcePagination | null>(null);
    const query = ref('');
    const loading = ref(true);
    const failed = ref(false);
    const request = useHttp<Record<string, never>, GitSourcePage>({});
    let generation = 0;
    let retryPage: number | undefined;
    let searchTimer: ReturnType<typeof setTimeout> | undefined;

    function cancelPending() {
        generation++;
        request.cancel();
        clearTimeout(searchTimer);
        searchTimer = undefined;
    }

    function listUrl(page?: number) {
        const search = query.value.trim() || undefined;
        return index.url({
            query: { page: page ?? (search ? 1 : undefined), search },
        });
    }

    async function load(page?: number, afterCreate = false) {
        cancelPending();
        const currentGeneration = generation;
        loading.value = true;
        failed.value = false;
        retryPage = page;

        function reportFailure(message: string) {
            if (currentGeneration !== generation) return;
            failed.value = true;
            showError(afterCreate ? t('create.refreshFailed') : message);
        }

        try {
            await request.get(listUrl(page), {
                onSuccess(response) {
                    if (currentGeneration !== generation) return;
                    sources.value = response.data;
                    pagination.value = response.meta;
                    if (!selectedSource.value) {
                        selectedSource.value = response.data[0] ?? null;
                    }
                },
                onError() {
                    reportFailure(t('sources.loadFailed'));
                },
            });
        } catch (error) {
            if (!isCancelledRequest(error)) {
                reportFailure(apiErrorMessage(error, 'sources.loadFailed'));
            }
        } finally {
            if (currentGeneration === generation) loading.value = false;
        }
    }

    function select(id: string) {
        if (selectedSource.value?.id === id) return;
        const source = sources.value.find((item) => item.id === id);
        if (source) selectedSource.value = source;
    }

    function reconcile(source: GitSource) {
        if (selectedSource.value?.id === source.id)
            selectedSource.value = source;
        sources.value = sources.value.map((item) =>
            item.id === source.id ? source : item,
        );
    }

    function retry() {
        void load(retryPage);
    }

    async function remove(source: GitSource) {
        cancelPending();
        const wasVisible = sources.value.some((item) => item.id === source.id);
        sources.value = sources.value.filter((item) => item.id !== source.id);
        if (selectedSource.value?.id === source.id) {
            selectedSource.value = sources.value[0] ?? null;
        }
        const meta = pagination.value;
        const lastPage = meta
            ? Math.max(
                  1,
                  Math.ceil((meta.total - Number(wasVisible)) / meta.per_page),
              )
            : 1;
        await load(Math.min(meta?.current_page ?? 1, lastPage));
    }

    watch(
        query,
        () => {
            cancelPending();
            loading.value = true;
            failed.value = false;
            searchTimer = setTimeout(() => void load(1), 300);
        },
        { flush: 'sync' },
    );

    onBeforeUnmount(() => {
        cancelPending();
    });

    return {
        sources,
        selectedSource,
        pagination,
        query,
        loading,
        failed,
        load,
        select,
        reconcile,
        retry,
        remove,
    };
}
