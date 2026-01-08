<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { globalAiState } from '@/state';
</script>

<template>
    <div
        class="flex aspect-square size-8 items-center justify-center rounded-md "
    >
        <AppLogoIcon
        :class="{ 'ai-active': globalAiState.isProcessing }"
        class="h-8 w-8 transition-colors duration-500"
    />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold"
            >Projector</span
        >
    </div>
</template>
<style scoped>
/* Only spin when the ai-active class is present on the component */
:deep(.ai-active .logo-wheel) {
    transform-origin: center;
    transform-box: fill-box;
    animation: logo-spin 3s linear infinite;
}

:deep(.ai-active .left-wheel) {
    animation-duration: 4s;
}

:deep(.ai-active .right-wheel) {
    animation-direction: reverse;
    animation-duration: 3s;
}

@keyframes logo-spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>
