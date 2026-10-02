<script setup lang="ts">
import { PhCaretLeft, PhCaretRight } from '@phosphor-icons/vue';
import { t } from '@/lib/translate';
import type { PaginationMeta } from '@/types/pagination';

defineProps<{
    pagination: PaginationMeta;
    loading: boolean;
    label: string;
}>();
defineEmits<{ page: [page: number] }>();
</script>

<template>
    <nav
        v-if="pagination.last_page > 1"
        class="flex items-center justify-between gap-2"
        :aria-label="label"
    >
        <button
            type="button"
            class="icon-button focus-ring disabled:cursor-default disabled:opacity-40"
            :disabled="loading || pagination.current_page <= 1"
            :aria-label="t('sidebar.previous')"
            @click="$emit('page', pagination.current_page - 1)"
        >
            <PhCaretLeft :size="18" aria-hidden="true" />
        </button>
        <span class="text-xs text-muted">{{
            t('sidebar.page', {
                page: pagination.current_page,
                pages: pagination.last_page,
            })
        }}</span>
        <button
            type="button"
            class="icon-button focus-ring disabled:cursor-default disabled:opacity-40"
            :disabled="
                loading || pagination.current_page >= pagination.last_page
            "
            :aria-label="t('sidebar.next')"
            @click="$emit('page', pagination.current_page + 1)"
        >
            <PhCaretRight :size="18" aria-hidden="true" />
        </button>
    </nav>
</template>
