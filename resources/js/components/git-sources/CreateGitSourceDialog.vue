<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { PhCheck, PhCircleNotch, PhX } from '@phosphor-icons/vue';
import { computed, nextTick, useId, useTemplateRef, watch } from 'vue';
import { store } from '@/actions/App/Http/Controllers/GitSourceController';
import AppDialog from '@/components/ui/AppDialog.vue';
import AppToast from '@/components/ui/AppToast.vue';
import { GitHubProvider } from '@/data/git-providers';
import {
    apiErrorMessage,
    errorMessage,
    isCancelledRequest,
} from '@/lib/api-errors';
import { t } from '@/lib/translate';
import type { GitSource, GitSourceResponse } from '@/types/git-source';

const props = defineProps<{ open: boolean; toast: string }>();
const emit = defineEmits<{
    'update:open': [open: boolean];
    create: [source: GitSource];
    error: [message: string];
    dismissToast: [];
}>();

const id = useId();
const accountInput = useTemplateRef<HTMLInputElement>('accountInput');
const form = useHttp<{ provider: string; account: string }, GitSourceResponse>({
    provider: GitHubProvider.id,
    account: '',
});
const error = computed(() =>
    form.errors.account ? errorMessage(form.errors.account) : '',
);

watch(
    () => props.open,
    (open) => {
        if (open) {
            form.account = '';
            form.provider = GitHubProvider.id;
            form.clearErrors();
        }
    },
);

async function submit() {
    if (form.processing) return;

    try {
        await form.post(store.url(), {
            onSuccess(response) {
                emit('create', response.data);
            },
            onError(errors) {
                emit(
                    'error',
                    errorMessage(
                        errors.account ?? errors.provider,
                        'create.failed',
                    ),
                );
            },
        });
        if (form.hasErrors) {
            await nextTick();
            accountInput.value?.focus();
        }
    } catch (failure) {
        if (!isCancelledRequest(failure))
            emit('error', apiErrorMessage(failure, 'create.failed'));
    }
}
</script>

<template>
    <AppDialog
        :open="open"
        :labelledby="`${id}-title`"
        :describedby="`${id}-description`"
        :close-disabled="form.processing"
        @update:open="emit('update:open', $event)"
    >
        <form
            class="flex flex-col gap-6 p-6 sm:p-7"
            novalidate
            :aria-busy="form.processing"
            @submit.prevent="submit"
        >
            <div class="flex items-start justify-between gap-4">
                <div class="flex flex-col gap-2">
                    <h2
                        :id="`${id}-title`"
                        class="text-xl font-semibold tracking-tight"
                    >
                        {{ t('create.title') }}
                    </h2>
                    <p
                        :id="`${id}-description`"
                        class="text-sm leading-relaxed text-muted"
                    >
                        {{ t('create.description') }}
                    </p>
                </div>
                <button
                    type="button"
                    class="icon-button focus-ring -mt-2 -mr-2"
                    :aria-label="t('create.close')"
                    :disabled="form.processing"
                    @click="emit('update:open', false)"
                >
                    <PhX :size="19" aria-hidden="true" />
                </button>
            </div>

            <div class="flex flex-col gap-2.5">
                <p :id="`${id}-provider`" class="text-sm font-medium">
                    {{ t('create.provider') }}
                </p>
                <div
                    :aria-labelledby="`${id}-provider`"
                    class="flex items-center gap-3 rounded-xl border border-line bg-sidebar p-3.5"
                >
                    <component
                        :is="GitHubProvider.icon"
                        :size="23"
                        weight="fill"
                        aria-hidden="true"
                    />
                    <span class="flex-1 text-sm font-medium">{{
                        GitHubProvider.name
                    }}</span>
                    <PhCheck
                        :size="18"
                        class="text-accent"
                        weight="bold"
                        aria-hidden="true"
                    />
                </div>
            </div>

            <div class="flex flex-col gap-2.5">
                <label :for="`${id}-account`" class="text-sm font-medium">{{
                    t('create.account')
                }}</label>
                <input
                    :id="`${id}-account`"
                    ref="accountInput"
                    v-model="form.account"
                    name="account"
                    autofocus
                    autocomplete="off"
                    autocapitalize="none"
                    spellcheck="false"
                    type="text"
                    :placeholder="t('create.placeholder')"
                    :disabled="form.processing"
                    :aria-invalid="!!error"
                    :aria-describedby="`${id}-hint${error ? ` ${id}-error` : ''}`"
                    :class="['text-input', { 'border-danger': error }]"
                    @input="form.clearErrors('account')"
                />
                <p
                    :id="`${id}-hint`"
                    class="text-xs leading-relaxed text-muted"
                >
                    {{ t('create.hint') }}
                </p>
                <p
                    v-if="error"
                    :id="`${id}-error`"
                    class="text-sm text-danger"
                    role="alert"
                >
                    {{ error }}
                </p>
            </div>

            <div class="flex justify-end gap-2 border-t border-line pt-5">
                <button
                    type="button"
                    class="secondary-button focus-ring"
                    :disabled="form.processing"
                    @click="emit('update:open', false)"
                >
                    {{ t('create.cancel') }}
                </button>
                <button
                    type="submit"
                    class="primary-button focus-ring disabled:cursor-wait disabled:opacity-70"
                    :disabled="form.processing"
                >
                    <PhCircleNotch
                        v-if="form.processing"
                        :size="17"
                        class="animate-spin motion-reduce:animate-none"
                        aria-hidden="true"
                    />
                    {{ t(form.processing ? 'create.saving' : 'create.submit') }}
                </button>
            </div>
        </form>
        <AppToast :message="toast" @dismiss="emit('dismissToast')" />
    </AppDialog>
</template>
