<script setup lang="ts">
import { computed } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
} from '@/components/ui/select';
import { PRIORITY_LABELS, priorityDotClasses } from '@/lib/constants';

const props = defineProps<{
    priority?: string | null;
    readOnly?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update', value: string): void;
}>();

// A dot only for the two ends of the scale that actually need to stand out — medium is the
// common/default case and stays unmarked so high/low aren't lost among a row of identical
// dots. No text label anywhere on the row itself; the priority name only ever shows inside
// the picker (below), never next to the dot.
const dotColor = computed(() => {
    const priority = props.priority ?? 'low';
    return priority === 'high' || priority === 'low' ? (priorityDotClasses[priority] ?? null) : null;
});
</script>

<template>
    <div v-if="readOnly" class="flex w-2.5 shrink-0 items-center justify-center">
        <span v-if="dotColor" :class="['h-1.5 w-1.5 rounded-full', dotColor]"></span>
    </div>
    <div v-else class="w-2.5 shrink-0">
        <Select :model-value="priority ?? 'low'" @update:model-value="(val) => emit('update', val as string)">
            <SelectTrigger class="h-auto w-2.5 justify-center border-none bg-transparent p-0 shadow-none [&>svg]:hidden">
                <span v-if="dotColor" :class="['h-1.5 w-1.5 rounded-full', dotColor]"></span>
            </SelectTrigger>
            <SelectContent align="start">
                <SelectItem v-for="(label, key) in PRIORITY_LABELS" :key="key" :value="key" class="text-[10px] font-black uppercase">
                    <div class="flex w-24 items-center justify-between">
                        {{ label }}
                        <div :class="[priorityDotClasses[key], 'h-2 w-2 rounded-full']"></div>
                    </div>
                </SelectItem>
            </SelectContent>
        </Select>
    </div>
</template>
