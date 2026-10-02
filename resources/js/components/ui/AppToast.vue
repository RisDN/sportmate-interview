<script setup lang="ts">
import { PhWarningCircle, PhX } from '@phosphor-icons/vue';
import { t } from '@/lib/translate';

defineProps<{ message: string }>();
defineEmits<{ dismiss: [] }>();
</script>

<template>
    <div
        v-if="message"
        class="fixed right-4 bottom-4 left-4 z-50 flex items-start gap-3 rounded-xl border border-danger/35 bg-surface p-4 text-foreground shadow-xl sm:left-auto sm:w-96"
        role="alert"
    >
        <PhWarningCircle
            :size="22"
            class="shrink-0 text-danger"
            aria-hidden="true"
        />
        <p class="min-w-0 flex-1 text-sm leading-relaxed">{{ message }}</p>
        <button
            type="button"
            class="focus-ring -mt-1 -mr-1 flex size-7 shrink-0 items-center justify-center rounded-lg text-muted hover:bg-hover hover:text-foreground"
            :aria-label="t('toast.dismiss')"
            @click="$emit('dismiss')"
        >
            <PhX :size="16" aria-hidden="true" />
        </button>
    </div>
</template>
