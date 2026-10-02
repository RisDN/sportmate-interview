<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { PhSidebarSimple } from '@phosphor-icons/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    shallowRef,
    useTemplateRef,
    watch,
} from 'vue';
import CreateGitSourceDialog from '@/components/git-sources/CreateGitSourceDialog.vue';
import GitSourceSidebar from '@/components/git-sources/GitSourceSidebar.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import AppDialog from '@/components/ui/AppDialog.vue';
import { useTheme } from '@/composables/useTheme';
import { mockGitSources } from '@/data/git-sources';
import { t } from '@/lib/translate';
import type { GitSource } from '@/types/git-source';
import type { ThemePreference } from '@/types/theme';

const page = usePage<{ theme: ThemePreference }>();
const { theme, toggleTheme } = useTheme(page.props.theme);
const sources = shallowRef<GitSource[]>([...mockGitSources]);
const selectedId = ref<string | null>(sources.value[0]?.id ?? null);
const query = ref('');
const mobileOpen = ref(false);
const createOpen = ref(false);
const announcement = ref('');
const mobileTrigger = useTemplateRef<HTMLButtonElement>('mobileTrigger');
const desktopSidebar =
    useTemplateRef<InstanceType<typeof GitSourceSidebar>>('desktopSidebar');
const selectedSource = computed(() =>
    sources.value.find((source) => source.id === selectedId.value),
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
    selectedId.value = id;
    mobileOpen.value = false;
}

async function addSource(source: GitSource) {
    const fromMobile = mobileOpen.value;
    sources.value = [...sources.value, source];
    selectedId.value = source.id;
    query.value = '';
    createOpen.value = false;
    await nextTick();
    mobileOpen.value = false;
    announcement.value = t('create.added', { name: source.account });
    if (fromMobile) {
        await nextTick();
        mobileTrigger.value?.focus();
    }
}

function closeDrawerOnDesktop(event: MediaQueryListEvent) {
    if (event.matches) mobileOpen.value = false;
}

onMounted(() => {
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
                @select="selectSource"
                @create="createOpen = true"
            />
        </div>

        <div class="flex min-w-0 flex-1 flex-col">
            <header
                class="flex min-h-20 shrink-0 items-center gap-3 border-b border-line/70 px-5 sm:px-8"
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
                <span class="text-sm font-medium text-muted">{{
                    t('page.title')
                }}</span>
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
                        <component
                            :is="selectedSource.provider.icon"
                            :size="40"
                            weight="fill"
                            aria-hidden="true"
                        />
                    </div>
                    <div class="flex w-full min-w-0 flex-col gap-2">
                        <h1
                            class="text-2xl leading-tight font-semibold tracking-tight wrap-anywhere sm:text-3xl"
                        >
                            {{ selectedSource.account }}
                        </h1>
                        <p class="text-sm text-muted">
                            {{ selectedSource.provider.name }}
                        </p>
                    </div>
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
            heading-id="mobile-sources-title"
            mobile
            @select="selectSource"
            @create="createOpen = true"
            @close="mobileOpen = false"
        />
    </AppDialog>

    <CreateGitSourceDialog
        v-model:open="createOpen"
        :sources="sources"
        @create="addSource"
    />
    <p class="sr-only" role="status">{{ announcement }}</p>
</template>
