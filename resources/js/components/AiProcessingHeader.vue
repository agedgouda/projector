<script setup lang="ts">
import { RefreshCw } from 'lucide-vue-next';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Alert, AlertTitle, AlertDescription } from "@/components/ui/alert";

defineProps<{
    isProcessing: boolean;
    progress: number;
    message: string;
}>();
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
            <div v-if="isProcessing" class="fixed top-0 left-0 right-0 z-[100] h-[3px] bg-indigo-100/20">
                <div
                    class="h-full bg-indigo-600 transition-all duration-500 ease-out shadow-[0_0_8px_rgba(79,70,229,0.5)]"
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
            <Alert v-if="isProcessing" class="bg-indigo-50 border-indigo-100 p-0 block shadow-sm overflow-hidden mb-6">
                <div class="p-4 flex justify-between items-center">
                    <div class="flex items-center gap-3">
                        <AppLogoIcon class="h-10 w-10 text-indigo-600" />
                        <div>
                            <AlertTitle class="text-sm font-black text-indigo-900 animate-pulse uppercase tracking-widest">
                                AI Sync Active
                            </AlertTitle>
                            <AlertDescription class="text-xs text-indigo-700/70 font-medium">
                                {{ message || 'Synchronizing project mapping...' }}
                            </AlertDescription>
                        </div>
                    </div>
                    <div class="flex flex-col items-end gap-1 mr-4">
                        <RefreshCw class="animate-spin text-indigo-400 h-5 w-5" />
                    </div>
                </div>
            </Alert>
        </transition>
    </div>
</template>
