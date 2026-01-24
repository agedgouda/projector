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
