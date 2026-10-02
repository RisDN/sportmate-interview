<script setup lang="ts">
import { onBeforeUnmount, useTemplateRef, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        open: boolean;
        labelledby: string;
        describedby?: string;
        variant?: 'modal' | 'sidebar';
    }>(),
    { variant: 'modal' },
);

const emit = defineEmits<{ 'update:open': [open: boolean] }>();
const dialog = useTemplateRef<HTMLDialogElement>('dialog');
let pressedBackdrop = false;

watch(
    () => [props.open, dialog.value] as const,
    ([open]) => {
        if (open && !dialog.value?.open) {
            dialog.value?.showModal();
        } else if (!open && dialog.value?.open) {
            dialog.value.close();
        }
    },
    { flush: 'post', immediate: true },
);

function close() {
    emit('update:open', false);
}

function syncClosedState() {
    if (!dialog.value?.open) close();
}

function onBackdropClick(event: MouseEvent) {
    if (pressedBackdrop && event.target === event.currentTarget) close();
    pressedBackdrop = false;
}

function containFocus(event: KeyboardEvent) {
    const controls = Array.from(
        dialog.value?.querySelectorAll<HTMLElement>(
            'button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])',
        ) ?? [],
    ).filter((element) => element.getClientRects().length > 0);
    const first = controls[0];
    const last = controls.at(-1);

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first?.focus();
    }
}

onBeforeUnmount(() => dialog.value?.close());
</script>

<template>
    <dialog
        ref="dialog"
        :aria-labelledby="labelledby"
        :aria-describedby="describedby"
        :class="[
            'app-dialog border border-line bg-surface p-0 text-foreground outline-none',
            variant === 'sidebar'
                ? 'app-drawer m-0 h-dvh max-h-dvh w-80 max-w-[calc(100%-3rem)] rounded-r-2xl border-l-0'
                : 'm-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-md rounded-2xl shadow-2xl',
        ]"
        @cancel.prevent="close"
        @close="syncClosedState"
        @pointerdown="pressedBackdrop = $event.target === $event.currentTarget"
        @click="onBackdropClick"
        @keydown.tab="containFocus"
    >
        <slot />
    </dialog>
</template>

<style scoped>
.app-dialog::backdrop {
    background: rgb(12 18 30 / 35%);
    backdrop-filter: blur(4px);
}

@media (prefers-reduced-motion: no-preference) {
    .app-dialog[open] {
        animation: dialog-in 180ms cubic-bezier(0.2, 0.8, 0.2, 1);
    }

    .app-drawer[open] {
        animation-name: drawer-in;
    }

    .app-dialog[open]::backdrop {
        animation: backdrop-in 150ms ease-out;
    }
}

@media (prefers-reduced-transparency: reduce) {
    .app-dialog::backdrop {
        backdrop-filter: none;
    }
}

@keyframes dialog-in {
    from {
        opacity: 0;
        transform: translateY(6px) scale(0.98);
    }
}

@keyframes drawer-in {
    from {
        opacity: 0;
        transform: translateX(-1rem);
    }
}

@keyframes backdrop-in {
    from {
        opacity: 0;
    }
}
</style>
