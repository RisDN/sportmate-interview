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
import GitSourceAvatar from '@/components/git-sources/GitSourceAvatar.vue';
import GitSourceSidebar from '@/components/git-sources/GitSourceSidebar.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import AppDialog from '@/components/ui/AppDialog.vue';
import AppToast from '@/components/ui/AppToast.vue';
import { useGitSources } from '@/composables/useGitSources';
import { useTheme } from '@/composables/useTheme';
import { getGitProvider } from '@/data/git-providers';
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
    loading,
    failed,
    load,
    select,
    retry,
} = useGitSources((message) => {
    toast.value = message;
});
const selectedId = computed(() => selectedSource.value?.id ?? null);
const query = ref('');
const mobileOpen = ref(false);
const createOpen = ref(false);
const announcement = ref('');
const mobileTrigger = useTemplateRef<HTMLButtonElement>('mobileTrigger');
const desktopSidebar =
    useTemplateRef<InstanceType<typeof GitSourceSidebar>>('desktopSidebar');
const selectedProvider = computed(() =>
    getGitProvider(selectedSource.value?.provider ?? ''),
);
const lastSynced = computed(() =>
    selectedSource.value?.last_synced_at
        ? new Intl.DateTimeFormat('en', {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(selectedSource.value.last_synced_at))
        : t('source.neverSynced'),
);
let desktopQuery: MediaQueryList | undefined;

watch(createOpen, async (open) => {
    if (open) return;
    await nextTick();
    if (mobileOpen.value) return;

    // Resizing can hide the original trigger while the dialog is open.
    if (desktopQuery?.matches) desktopSidebar.value?.focusCreate();
    else mobileTrigger.value?.focus();
});

function selectSource(id: string) {
    select(id);
    mobileOpen.value = false;
}

function openCreate() {
    toast.value = '';
    createOpen.value = true;
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
                class="flex min-h-0 flex-1 items-center justify-center overflow-y-auto p-6 sm:p-10"
                :aria-label="t('source.selected')"
            >
                <div
                    v-if="selectedSource"
                    :key="selectedSource.id"
                    class="flex w-full max-w-lg flex-col items-center gap-5 pb-16 text-center"
                >
                    <div
                        class="flex size-20 items-center justify-center rounded-3xl border border-line/70 bg-surface shadow-sm"
                    >
                        <GitSourceAvatar
                            :provider="selectedSource.provider"
                            :url="selectedSource.avatar_url"
                            :size="40"
                        />
                    </div>
                    <div class="flex w-full min-w-0 flex-col gap-2">
                        <h1
                            class="text-2xl leading-tight font-semibold tracking-tight wrap-anywhere sm:text-3xl"
                        >
                            {{ selectedSource.name }}
                        </h1>
                        <p class="text-sm text-muted">
                            {{ selectedSource.account }} ·
                            {{ selectedProvider.name }}
                        </p>
                    </div>
                    <dl
                        class="flex w-full flex-col gap-4 rounded-2xl border border-line bg-surface p-5 text-left text-sm"
                    >
                        <div class="flex flex-col gap-1">
                            <dt class="text-xs text-muted">
                                {{ t('source.profile') }}
                            </dt>
                            <dd>
                                <a
                                    :href="selectedSource.url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="focus-ring rounded-sm wrap-anywhere text-accent underline-offset-4 hover:underline"
                                    >{{ selectedSource.url }}</a
                                >
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-muted">
                                {{ t('source.accountType') }}
                            </dt>
                            <dd>
                                {{
                                    t(
                                        selectedSource.account_type ===
                                            'organization'
                                            ? 'source.organization'
                                            : 'source.user',
                                    )
                                }}
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-muted">
                                {{ t('source.lastSync') }}
                            </dt>
                            <dd>
                                <time
                                    v-if="selectedSource.last_synced_at"
                                    :datetime="selectedSource.last_synced_at"
                                    >{{ lastSynced }}</time
                                ><span v-else>{{ lastSynced }}</span>
                            </dd>
                        </div>
                    </dl>
                </div>
                <div v-else class="flex flex-col gap-2 text-center">
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
    <AppToast
        v-if="!mobileOpen && !createOpen"
        :message="toast"
        @dismiss="toast = ''"
    />
    <p class="sr-only" role="status">{{ announcement }}</p>
</template>
