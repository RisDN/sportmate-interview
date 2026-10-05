<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { PhSidebarSimple } from '@phosphor-icons/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import CreateGitSourceDialog from '@/components/git-sources/CreateGitSourceDialog.vue';
import DeleteGitSourceDialog from '@/components/git-sources/DeleteGitSourceDialog.vue';
import GitSourceProfile from '@/components/git-sources/GitSourceProfile.vue';
import GitSourceSidebar from '@/components/git-sources/GitSourceSidebar.vue';
import RepositoryList from '@/components/repositories/RepositoryList.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import AppDialog from '@/components/ui/AppDialog.vue';
import AppToast from '@/components/ui/AppToast.vue';
import { useGitSources } from '@/composables/useGitSources';
import { useGitSourceSync } from '@/composables/useGitSourceSync';
import { useRemoteRepositories } from '@/composables/useRemoteRepositories';
import { useTheme } from '@/composables/useTheme';
import { t } from '@/lib/translate';
import type { GitSource } from '@/types/git-source';
import type { ThemePreference } from '@/types/theme';

const page = usePage<{ theme: ThemePreference }>();
const { theme, toggleTheme } = useTheme(page.props.theme);
const toast = ref('');
const {
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
} = useGitSources((message) => {
    toast.value = message;
});
const selectedId = computed(() => selectedSource.value?.id ?? null);
const {
    repositories,
    filters: repositoryFilters,
    languages: repositoryLanguages,
    pagination: repositoryPagination,
    loading: repositoriesLoading,
    refreshing: repositoriesRefreshing,
    error: repositoriesError,
    load: loadRepositories,
    refresh: refreshRepositories,
    retry: retryRepositories,
    observation: repositoryObservation,
    reconcileSnapshot: reconcileRepositories,
} = useRemoteRepositories(selectedId);
const {
    starting: syncStarting,
    active: syncActive,
    detailError,
    syncError,
    refresh: refreshSync,
    startSync,
} = useGitSourceSync(
    selectedSource,
    reconcile,
    repositoryObservation,
    reconcileRepositories,
    remove,
);
const mobileOpen = ref(false);
const createOpen = ref(false);
const deleteTarget = ref<GitSource | null>(null);
const announcement = ref('');
const mobileTrigger = useTemplateRef<HTMLButtonElement>('mobileTrigger');
const mainContent = useTemplateRef<HTMLElement>('mainContent');
const repositoryList =
    useTemplateRef<InstanceType<typeof RepositoryList>>('repositoryList');
const desktopSidebar =
    useTemplateRef<InstanceType<typeof GitSourceSidebar>>('desktopSidebar');
let desktopQuery: MediaQueryList | undefined;

watch(selectedId, async () => {
    await nextTick();
    mainContent.value?.scrollTo({ top: 0 });
});

watch(createOpen, async (open) => {
    if (open) return;
    await nextTick();
    if (mobileOpen.value) return;

    // Resizing can hide the original trigger while the dialog is open.
    if (desktopQuery?.matches) desktopSidebar.value?.focusCreate();
    else mobileTrigger.value?.focus();
});

function selectSource(id: string) {
    if (selectedId.value === id) {
        void refreshSync();
        refreshRepositories();
    }
    select(id);
    mobileOpen.value = false;
}

function openCreate() {
    toast.value = '';
    createOpen.value = true;
}

async function changeRepositoryPage(pageNumber: number) {
    const list = repositoryList.value;
    await loadRepositories(pageNumber);
    await nextTick();

    if (
        repositoryList.value === list &&
        repositoryPagination.value?.current_page === pageNumber &&
        !repositoriesLoading.value &&
        !repositoriesError.value
    ) {
        list?.focusHeading();
    }
}

async function addSource(source: GitSource) {
    const fromMobile = mobileOpen.value;
    selectedSource.value = source;
    toast.value = '';
    query.value = '';
    createOpen.value = false;
    await nextTick();
    mobileOpen.value = false;
    announcement.value = t('create.added', { name: source.account });
    if (fromMobile) {
        await nextTick();
        mobileTrigger.value?.focus();
    }
    await load(1, true);
}

async function deleteSource(source: GitSource) {
    deleteTarget.value = null;
    announcement.value = t('delete.deleted', { name: source.name });
    await remove(source);
    await nextTick();
    mainContent.value?.focus({ preventScroll: true });
}

function closeDrawerOnDesktop(event: MediaQueryListEvent) {
    if (event.matches) mobileOpen.value = false;
}

onMounted(() => {
    void load();
    desktopQuery = window.matchMedia('(min-width: 768px)');
    desktopQuery.addEventListener('change', closeDrawerOnDesktop);
});

onBeforeUnmount(() =>
    desktopQuery?.removeEventListener('change', closeDrawerOnDesktop),
);
</script>

<template>
    <Head :title="t('page.title')" />

    <div class="flex h-dvh min-h-0 overflow-hidden">
        <div class="hidden w-72 shrink-0 border-r border-line md:block">
            <GitSourceSidebar
                ref="desktopSidebar"
                v-model:query="query"
                :sources="sources"
                :selected-id="selectedId"
                :pagination="pagination"
                :loading="loading"
                :failed="failed"
                @select="selectSource"
                @create="openCreate"
                @page="load"
                @retry="retry"
            />
        </div>

        <div class="flex min-w-0 flex-1 flex-col">
            <header
                class="flex min-h-20 shrink-0 items-center gap-3 px-5 sm:px-8"
            >
                <button
                    ref="mobileTrigger"
                    type="button"
                    class="icon-button focus-ring -ml-2 md:hidden"
                    :aria-label="t('sidebar.open')"
                    :aria-expanded="mobileOpen"
                    aria-haspopup="dialog"
                    @click="mobileOpen = true"
                >
                    <PhSidebarSimple :size="22" aria-hidden="true" />
                </button>
                <ThemeToggle
                    :theme="theme"
                    class="ml-auto"
                    @toggle="toggleTheme"
                />
            </header>

            <main
                ref="mainContent"
                tabindex="-1"
                class="min-h-0 flex-1 overflow-y-auto px-5 pt-4 pb-10 outline-none sm:px-10 sm:pb-12"
                :aria-label="t('source.selected')"
            >
                <div
                    v-if="selectedSource"
                    :key="selectedSource.id"
                    class="mx-auto flex w-full max-w-5xl flex-col gap-10"
                >
                    <GitSourceProfile
                        :source="selectedSource"
                        :active="syncActive"
                        :starting="syncStarting"
                        :detail-error="detailError"
                        :sync-error="syncError"
                        @sync="startSync"
                        @refresh="refreshSync"
                        @delete="deleteTarget = selectedSource"
                    />
                    <RepositoryList
                        ref="repositoryList"
                        v-model:filters="repositoryFilters"
                        :repositories="repositories"
                        :languages="repositoryLanguages"
                        :pagination="repositoryPagination"
                        :loading="repositoriesLoading"
                        :refreshing="repositoriesRefreshing"
                        :error="repositoriesError"
                        :sync-status="selectedSource.sync_status"
                        :sync-active="syncActive"
                        @page="changeRepositoryPage"
                        @retry="retryRepositories"
                    />
                </div>
                <div
                    v-else
                    class="flex h-full flex-col items-center justify-center gap-2 text-center"
                >
                    <h1 class="text-xl font-semibold">
                        {{ t('source.none') }}
                    </h1>
                    <p class="text-sm text-muted">{{ t('source.noneHint') }}</p>
                </div>
            </main>
        </div>
    </div>

    <AppDialog
        v-model:open="mobileOpen"
        labelledby="mobile-sources-title"
        variant="sidebar"
    >
        <GitSourceSidebar
            v-model:query="query"
            :sources="sources"
            :selected-id="selectedId"
            :pagination="pagination"
            :loading="loading"
            :failed="failed"
            heading-id="mobile-sources-title"
            mobile
            @select="selectSource"
            @create="openCreate"
            @close="mobileOpen = false"
            @page="load"
            @retry="retry"
        />
        <AppToast v-if="!createOpen" :message="toast" @dismiss="toast = ''" />
    </AppDialog>

    <CreateGitSourceDialog
        v-model:open="createOpen"
        :toast="createOpen ? toast : ''"
        @create="addSource"
        @error="toast = $event"
        @dismiss-toast="toast = ''"
    />
    <DeleteGitSourceDialog
        :source="deleteTarget"
        @close="deleteTarget = null"
        @deleted="deleteSource"
    />
    <AppToast
        v-if="!mobileOpen && !createOpen"
        :message="toast"
        @dismiss="toast = ''"
    />
    <p class="sr-only" role="status">{{ announcement }}</p>
</template>
