<script setup lang="ts">
import { computed } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue
} from '@/components/ui/select';
import {
    STATUS_LABELS,
    PRIORITY_LABELS,
    statusDotClasses,
    priorityDotClasses
} from '@/lib/constants';

/**
 * Props Definition
 */
interface Props {
    label: string;
    modelValue: string | number | null | undefined;
    type: 'status' | 'priority' | 'user';
    options?: any[]; // Only required for type='user'
}

const props = defineProps<Props>();
const emit = defineEmits(['update:modelValue']);

/**
 * Normalize the value for the Radix Select component.
 * It strictly requires strings for values.
 */
const selectValue = computed(() => {
    if (props.modelValue === null || props.modelValue === undefined) return 'unassigned';
    return props.modelValue.toString();
});

/**
 * Handle change and emit back to parent
 */
const onUpdate = (val: any) => {
    // val might be 'unassigned' (string) or null
    const output = val === 'unassigned' ? null : val;
    emit('update:modelValue', output);
};

/**
 * Helpers for dot indicators
 */
const getDotClass = (val: string) => {
    if (props.type === 'status') return statusDotClasses[val] || '';
    if (props.type === 'priority') return priorityDotClasses[val] || '';
    return '';
};

const labels = computed(() => {
    if (props.type === 'status') return STATUS_LABELS;
    if (props.type === 'priority') return PRIORITY_LABELS;
    return {};
});
</script>

<template>
    <div class="flex justify-between items-center h-[24px] group">
        <span class="text-slate-500 text-xs">{{ label }}</span>

        <Select :model-value="selectValue" @update:model-value="onUpdate">
            <SelectTrigger class="h-auto p-0 border-none bg-transparent hover:bg-slate-100 rounded-md transition-all shadow-none w-auto outline-none">
                <div class="px-2 py-1">
                    <span class="relative left-[10px] font-black uppercase tracking-[0.12em] text-slate-700 text-[10px] flex items-center">
                        <SelectValue />
                        <div
                            v-if="type !== 'user'"
                            :class="[getDotClass(selectValue), 'w-2 h-2 rounded-full ml-2 flex-shrink-0']"
                        ></div>
                    </span>
                </div>
            </SelectTrigger>

            <SelectContent align="end" class="min-w-[160px]">
                <template v-if="type === 'user'">
                    <SelectItem value="unassigned" class="text-[10px] uppercase font-bold text-slate-400">
                        Unassigned
                    </SelectItem>
                    <SelectItem
                        v-for="user in options"
                        :key="user.id"
                        :value="user.id.toString()"
                        class="text-[10px] uppercase font-bold"
                    >
                        {{ user.name }}
                    </SelectItem>
                </template>

                <template v-else>
                    <SelectItem
                        v-for="(label, key) in labels"
                        :key="key"
                        :value="key"
                        class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-700 cursor-pointer focus:bg-slate-50"
                    >
                        <div class="flex items-center justify-between w-full min-w-[120px]">
                            <span>{{ label }}</span>
                            <div :class="[getDotClass(key as string), 'w-2 h-2 rounded-full ml-4 flex-shrink-0']"></div>
                        </div>
                    </SelectItem>
                </template>
            </SelectContent>
        </Select>
    </div>
</template>

<style scoped>
/* Ensure no focus rings or borders leak through */
:deep(button[role="combobox"]) {
    outline: none !important;
    box-shadow: none !important;
    border: none !important;
}
</style>
