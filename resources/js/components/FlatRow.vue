<script setup lang="ts">
import { computed } from 'vue';
import { FLAT_ROW_HOVER, FLAT_ROW_SELECTED, FLAT_ROW_ACCENT_BAR } from '@/lib/flat-ui';

const props = withDefaults(defineProps<{
    selected?: boolean;
    clickable?: boolean;
    height?: 'sm' | 'md';
    // Position within its list, for an alternating-stripe background — matches
    // OrgUserTable.vue's rule. Omitted by callers that don't want striping.
    rowIndex?: number;
    // Opts out of the row hover background — for callers that decided against it
    // (Roles, Project Portfolio, Transformations, Project Types, Team tab, Clients tab).
    // Other callers keep the hover by default.
    noHover?: boolean;
    // Keeps the #actions slot visible at all times instead of only on row hover/focus —
    // same opt-in set as noHover above.
    alwaysShowActions?: boolean;
}>(), {
    selected: false,
    clickable: false,
    height: 'sm',
    noHover: false,
    alwaysShowActions: false,
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
    props.selected ? FLAT_ROW_SELECTED : (props.noHover ? '' : FLAT_ROW_HOVER),
    !props.selected && props.rowIndex !== undefined && props.rowIndex % 2 === 1
        ? 'bg-projector-primary-100/70 dark:bg-projector-primary-950/25'
        : '',
]);

const actionsClasses = computed(() => [
    'flex items-center gap-1 shrink-0 ml-2',
    props.alwaysShowActions ? '' : 'opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity',
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

        <div v-if="$slots.actions" :class="actionsClasses">
            <slot name="actions" />
        </div>
    </div>
</template>
