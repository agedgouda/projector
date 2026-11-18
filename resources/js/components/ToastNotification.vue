<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    ToastDescription,
    ToastProvider,
    ToastRoot,
    ToastTitle,
    ToastViewport,
} from 'reka-ui';
import { computed, ref, watch } from 'vue';

const page = usePage();
const flash = computed(
    () => (page.props as any).flash as { success?: string; error?: string },
);
const open = ref(false);
const message = ref('');

watch(
    () => flash.value?.success,
    (newSuccess) => {
        if (newSuccess) {
            message.value = newSuccess;
            open.value = true;
        }
    },
    { immediate: true },
);
</script>

<template>
    <ToastProvider>
        <ToastRoot
            v-model:open="open"
            :duration="5000"
            class="fixed right-6 bottom-6 z-[100] w-80 animate-in rounded-xl border border-gray-200 bg-white p-4 shadow-xl slide-in-from-right-5 dark:border-gray-700 dark:bg-gray-800"
        >
            <div class="flex items-start gap-3">
                <div
                    class="mt-0.5 rounded-full bg-green-100 p-1 dark:bg-green-900/30"
                >
                    <svg
                        class="h-4 w-4 text-green-600"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="3"
                            d="M5 13l4 4L19 7"
                        />
                    </svg>
                </div>
                <div class="flex flex-col gap-1">
                    <ToastTitle
                        class="text-sm font-bold text-gray-900 dark:text-white"
                        >Success</ToastTitle
                    >
                    <ToastDescription
                        class="text-xs text-gray-500 dark:text-gray-400"
                    >
                        {{ message }}
                    </ToastDescription>
                </div>
            </div>
        </ToastRoot>

        <ToastViewport class="fixed right-0 bottom-0 z-[100] p-6" />
    </ToastProvider>
</template>
