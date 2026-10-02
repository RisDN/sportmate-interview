import { useHttp } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, shallowRef } from 'vue';
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
    const loading = ref(true);
    const failed = ref(false);
    const request = useHttp<Record<string, never>, GitSourcePage>({});
    let generation = 0;
    let retryPage: number | undefined;

    async function load(page?: number, afterCreate = false) {
        const currentGeneration = ++generation;
        request.cancel();
        loading.value = true;
        failed.value = false;
        retryPage = page;

        function reportFailure(message: string) {
            if (currentGeneration !== generation) return;
            failed.value = true;
            showError(afterCreate ? t('create.refreshFailed') : message);
        }

        try {
            await request.get(
                index.url(page === undefined ? undefined : { query: { page } }),
                {
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
                },
            );
        } catch (error) {
            if (!isCancelledRequest(error)) {
                reportFailure(apiErrorMessage(error, 'sources.loadFailed'));
            }
        } finally {
            if (currentGeneration === generation) loading.value = false;
        }
    }

    function select(id: string) {
        const source = sources.value.find((item) => item.id === id);
        if (source) selectedSource.value = source;
    }

    function retry() {
        void load(retryPage);
    }

    onBeforeUnmount(() => {
        generation++;
        request.cancel();
    });

    return {
        sources,
        selectedSource,
        pagination,
        loading,
        failed,
        load,
        select,
        retry,
    };
}
