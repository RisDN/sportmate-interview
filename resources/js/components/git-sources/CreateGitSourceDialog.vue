<script setup lang="ts">
import { PhCheck, PhX } from '@phosphor-icons/vue';
import { computed, ref, useId, useTemplateRef, watch } from 'vue';
import AppDialog from '@/components/ui/AppDialog.vue';
import { GitHubProvider } from '@/data/git-sources';
import { t } from '@/lib/translate';
import type { GitSource } from '@/types/git-source';

const props = defineProps<{ open: boolean; sources: readonly GitSource[] }>();
const emit = defineEmits<{
    'update:open': [open: boolean];
    create: [source: GitSource];
}>();

const id = useId();
const accountInput = useTemplateRef<HTMLInputElement>('accountInput');
const account = ref('');
const touched = ref(false);
const normalizedAccount = computed(() => account.value.trim());

const error = computed(() => {
    const name = normalizedAccount.value;
    if (!name) return t('create.required');
    if (name.length > 39 || !/^[a-z\d]+(?:-[a-z\d]+)*$/i.test(name)) {
        return t('create.invalid');
    }
    if (
        props.sources.some(
            (source) =>
                source.provider.id === GitHubProvider.id &&
                source.account.toLowerCase() === name.toLowerCase(),
        )
    ) {
        return t('create.duplicate');
    }
    return '';
});

watch(
    () => props.open,
    (open) => {
        if (open) {
            account.value = '';
            touched.value = false;
        }
    },
);

function submit() {
    touched.value = true;
    if (error.value) {
        accountInput.value?.focus();
        return;
    }
    emit('create', {
        id: `${GitHubProvider.id}:${normalizedAccount.value.toLowerCase()}`,
        provider: GitHubProvider,
        account: normalizedAccount.value,
    });
    emit('update:open', false);
}
</script>

<template>
    <AppDialog
        :open="open"
        :labelledby="`${id}-title`"
        :describedby="`${id}-description`"
        @update:open="emit('update:open', $event)"
    >
        <form
            class="flex flex-col gap-6 p-6 sm:p-7"
            novalidate
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
                    v-model="account"
                    autofocus
                    autocomplete="off"
                    autocapitalize="none"
                    spellcheck="false"
                    type="text"
                    :placeholder="t('create.placeholder')"
                    :aria-invalid="touched && !!error"
                    :aria-describedby="`${id}-hint${touched && error ? ` ${id}-error` : ''}`"
                    :class="[
                        'text-input',
                        { 'border-danger': touched && error },
                    ]"
                    @blur="touched = true"
                />
                <p
                    :id="`${id}-hint`"
                    class="text-xs leading-relaxed text-muted"
                >
                    {{ t('create.hint') }}
                </p>
                <p
                    v-if="touched && error"
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
                    @click="emit('update:open', false)"
                >
                    {{ t('create.cancel') }}
                </button>
                <button type="submit" class="primary-button focus-ring">
                    {{ t('create.submit') }}
                </button>
            </div>
        </form>
    </AppDialog>
</template>
