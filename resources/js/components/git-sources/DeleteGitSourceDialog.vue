<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { PhCircleNotch, PhTrash } from '@phosphor-icons/vue';
import { ref, useId, watch } from 'vue';
import { destroy } from '@/actions/App/Http/Controllers/GitSourceController';
import AppDialog from '@/components/ui/AppDialog.vue';
import { apiErrorMessage, isCancelledRequest } from '@/lib/api-errors';
import { t } from '@/lib/translate';
import type { GitSource, GitSourceResponse } from '@/types/git-source';

const props = defineProps<{ source: GitSource | null }>();
const emit = defineEmits<{
    close: [];
    deleted: [source: GitSource];
}>();
const id = useId();
const error = ref('');
const request = useHttp<Record<string, never>, GitSourceResponse>({});

watch(
    () => props.source?.id,
    () => {
        error.value = '';
    },
);

async function submit() {
    const source = props.source;
    if (!source || request.processing) return;
    error.value = '';

    try {
        await request.delete(destroy.url({ gitSource: Number(source.id) }), {
            onSuccess() {
                emit('deleted', source);
            },
            onError() {
                error.value = t('delete.failed');
            },
        });
    } catch (failure) {
        if (!isCancelledRequest(failure)) {
            error.value = apiErrorMessage(failure, 'delete.failed');
        }
    }
}
</script>

<template>
    <AppDialog
        :open="source !== null"
        :labelledby="`${id}-title`"
        :describedby="`${id}-description`"
        :close-disabled="request.processing"
        @update:open="!$event && emit('close')"
    >
        <form
            class="flex flex-col gap-6 p-6 sm:p-7"
            :aria-busy="request.processing"
            @submit.prevent="submit"
        >
            <div class="flex flex-col gap-3">
                <h2
                    :id="`${id}-title`"
                    class="text-xl font-semibold tracking-tight"
                >
                    {{ t('delete.title') }}
                </h2>
                <p
                    :id="`${id}-description`"
                    class="text-sm leading-relaxed text-muted"
                >
                    {{ t('delete.description', { name: source?.name ?? '' }) }}
                </p>
                <p v-if="source" class="text-sm font-medium wrap-anywhere">
                    {{ source.account }}
                </p>
            </div>
            <p v-if="error" class="text-sm text-danger" role="alert">
                {{ error }}
            </p>
            <div
                class="flex flex-wrap justify-end gap-2 border-t border-line pt-5"
            >
                <button
                    type="button"
                    autofocus
                    class="secondary-button focus-ring"
                    :disabled="request.processing"
                    @click="emit('close')"
                >
                    {{ t('delete.cancel') }}
                </button>
                <button
                    type="submit"
                    class="secondary-button focus-ring gap-2 border-danger/40 text-danger hover:bg-danger/10 disabled:cursor-wait disabled:opacity-70"
                    :disabled="request.processing"
                >
                    <PhCircleNotch
                        v-if="request.processing"
                        :size="17"
                        class="animate-spin motion-reduce:animate-none"
                        aria-hidden="true"
                    />
                    <PhTrash v-else :size="17" aria-hidden="true" />
                    {{
                        t(
                            request.processing
                                ? 'delete.deleting'
                                : 'delete.confirm',
                        )
                    }}
                </button>
            </div>
        </form>
    </AppDialog>
</template>
