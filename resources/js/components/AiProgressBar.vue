<script setup lang="ts">
defineProps<{
    isProcessing: boolean;
    progress: number;
}>();
</script>

<template>
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
</template>
