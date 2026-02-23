<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <svg width="200" height="200" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
    <rect x="50" y="80" width="100" height="80" fill="#8B4513" />
    <path d="M40 90 L100 30 L160 90" stroke="#5D2E0A" stroke-width="12" fill="none" stroke-linejoin="round"/>
    <circle cx="100" cy="120" r="25" fill="#3E1F07" />
    <path d="M90 170 Q100 190 110 170 Z" fill="#D2B48C" />
    <rect x="92" y="165" width="16" height="8" rx="2" fill="#5D2E0A" />
    </svg>
</template>
