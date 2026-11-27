<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { RefreshCw } from '@lucide/vue';

withDefaults(
    defineProps<{
        isProcessing: boolean;
        progress: number;
        message: string;
        title?: string;
    }>(),
    { title: 'AI Sync Active' },
);
</script>

<template>
    <div class="contents">
        <transition
            enter-active-class="transition-opacity duration-300"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-500"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="isProcessing"
                class="fixed top-0 right-0 left-0 z-[100] h-[3px] bg-projector-primary-100/20"
            >
                <div
                    class="h-full bg-projector-primary-600 shadow-[0_0_8px_rgba(198,54,21,0.5)] transition-all duration-500 ease-out"
                    :style="{ width: `${progress}%` }"
                ></div>
            </div>
        </transition>

        <transition
            enter-active-class="transition duration-500"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-300"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-2"
        >
            <Alert
                v-if="isProcessing"
                class="mb-6 block overflow-hidden border-projector-primary-100 bg-projector-primary-50 p-0 shadow-sm"
            >
                <div class="flex items-center justify-between p-4">
                    <div class="flex items-center gap-3">
                        <AppLogoIcon
                            class="h-10 w-10 text-projector-primary-600"
                        />
                        <div>
                            <AlertTitle
                                class="animate-pulse text-sm font-black tracking-widest text-projector-primary-900 uppercase"
                            >
                                {{ title }}
                            </AlertTitle>
                            <AlertDescription
                                class="text-xs font-medium text-projector-primary-700/70"
                            >
                                {{
                                    message ||
                                    'Synchronizing project mapping...'
                                }}
                            </AlertDescription>
                        </div>
                    </div>
                    <div class="mr-4 flex flex-col items-end gap-1">
                        <RefreshCw
                            class="h-5 w-5 animate-spin text-projector-primary-400"
                        />
                    </div>
                </div>
            </Alert>
        </transition>
    </div>
</template>
