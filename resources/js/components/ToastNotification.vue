<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { ToastRoot, ToastTitle, ToastDescription, ToastViewport, ToastProvider } from 'reka-ui';

const page = usePage();
const flash = computed(() => (page.props as any).flash as { success?: string; error?: string });
const open = ref(false);
const message = ref('');

watch(() => flash.value?.success, (newSuccess) => {
  if (newSuccess) {
    message.value = newSuccess;
    open.value = true;
  }
}, { immediate: true });
</script>

<template>
  <ToastProvider>
    <ToastRoot
      v-model:open="open"
      :duration="5000"
      class="fixed bottom-6 right-6 z-[100] bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-xl rounded-xl p-4 w-80 animate-in slide-in-from-right-5"
    >
      <div class="flex items-start gap-3">
        <div class="mt-0.5 bg-green-100 dark:bg-green-900/30 p-1 rounded-full">
            <svg class="w-4 h-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" /></svg>
        </div>
        <div class="flex flex-col gap-1">
          <ToastTitle class="font-bold text-gray-900 dark:text-white text-sm">Success</ToastTitle>
          <ToastDescription class="text-xs text-gray-500 dark:text-gray-400">
            {{ message }}
          </ToastDescription>
        </div>
      </div>
    </ToastRoot>

    <ToastViewport class="fixed bottom-0 right-0 p-6 z-[100]" />
  </ToastProvider>
</template>
