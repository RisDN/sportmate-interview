<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { getGitProvider } from '@/data/git-providers';

const props = withDefaults(
    defineProps<{ provider: string; url: string | null; size?: number }>(),
    { size: 21 },
);
const failed = ref(false);
const provider = computed(() => getGitProvider(props.provider));

watch(
    () => props.url,
    () => {
        failed.value = false;
    },
);
</script>

<template>
    <img
        v-if="url && !failed"
        :src="url"
        alt=""
        class="size-full rounded-[inherit] object-cover"
        loading="lazy"
        referrerpolicy="no-referrer"
        @error="failed = true"
    />
    <component
        :is="provider.icon"
        v-else
        :size="size"
        weight="fill"
        aria-hidden="true"
    />
</template>
