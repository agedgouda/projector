<script setup lang="ts">
import { computed } from 'vue';
import { FLAT_ROW_HOVER, FLAT_ROW_SELECTED, FLAT_ROW_ACCENT_BAR } from '@/lib/flat-ui';

const props = withDefaults(defineProps<{
    selected?: boolean;
    clickable?: boolean;
    height?: 'sm' | 'md';
}>(), {
    selected: false,
    clickable: false,
    height: 'sm',
});

defineEmits<{
    (e: 'click', event: MouseEvent): void;
}>();

const heightClasses: Record<string, string> = {
    sm: 'h-9 gap-2.5',
    md: 'h-12 gap-3',
};

const rowClasses = computed(() => [
    'group relative flex items-center px-2 rounded-md transition-colors',
    heightClasses[props.height],
    props.clickable ? 'cursor-pointer' : '',
    props.selected ? FLAT_ROW_SELECTED : FLAT_ROW_HOVER,
]);
</script>

<template>
    <div :class="rowClasses" @click="$emit('click', $event)">
        <div v-if="selected" :class="FLAT_ROW_ACCENT_BAR"></div>

        <slot name="leading" />

        <div class="flex-1 flex items-center gap-2.5 min-w-0">
            <slot />
        </div>

        <div v-if="$slots.trailing" class="hidden md:flex items-center gap-3 shrink-0 ml-3">
            <slot name="trailing" />
        </div>

        <div v-if="$slots.actions" class="flex items-center gap-1 shrink-0 ml-2 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity">
            <slot name="actions" />
        </div>
    </div>
</template>
