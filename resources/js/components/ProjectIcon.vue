<script setup lang="ts">
import * as Icons from 'lucide-vue-next';
import { computed, type Component } from 'vue';

const props = defineProps<{
    name: string | null;
    size?: number | string;
}>();

const iconSize = computed(() => Number(props.size || 16));

// We cast the return type to Component to tell TS this is a valid Vue component
const iconComponent = computed(() => {
    if (!props.name) return Icons.FolderRoot as Component;

    // Look up the icon in the library
    const Icon = (Icons as any)[props.name];

    return (Icon || Icons.FolderRoot) as Component;
});
</script>

<template>
    <component
        :is="iconComponent"
        :size="iconSize"
        class="shrink-0"
    />
</template>
