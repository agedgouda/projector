<script setup lang="ts">
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Textarea } from '@/components/ui/textarea';
import { Sparkles } from 'lucide-vue-next';

const props = defineProps<{
    modelValue: string | undefined;
}>();

const emit = defineEmits<{
    'update:modelValue': [string];
}>();
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md transition-colors"
                :class="
                    props.modelValue
                        ? 'text-amber-500 hover:bg-amber-50 dark:hover:bg-amber-950/30'
                        : 'text-slate-300 hover:bg-slate-100 hover:text-slate-500 dark:hover:bg-white/10'
                "
                title="Custom AI processing instructions"
            >
                <Sparkles class="h-3.5 w-3.5" />
            </button>
        </PopoverTrigger>
        <PopoverContent align="end" class="w-72 space-y-2 p-3">
            <p
                class="text-[10px] font-black tracking-widest text-slate-400 uppercase"
            >
                AI Processing Instructions
            </p>
            <p class="text-[10px] text-slate-400">
                Overrides default processing for this import. Leave blank to use
                standard processing.
            </p>
            <Textarea
                :model-value="modelValue"
                placeholder="e.g. Clean this up into full meeting notes, eliminating anything personal..."
                class="min-h-20 text-[13px]"
                @update:model-value="emit('update:modelValue', String($event))"
            />
        </PopoverContent>
    </Popover>
</template>
