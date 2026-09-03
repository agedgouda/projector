<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { X } from 'lucide-vue-next';

// The text-source counterpart to ImportTransformationPassEditor.vue — there's no column mapping
// here, just a plain-English extraction_rule the user can read and tighten, since there are no
// columns/rows to point at. See ImportTransformationModal.vue, which renders one of these per
// detected/saved pass when the source is text rather than a spreadsheet.
defineProps<{
    listType: 'task' | 'event';
    extractionRule: string;
    rationale?: string | null;
    removable?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:extractionRule', value: string): void;
    (e: 'remove'): void;
}>();
</script>

<template>
    <div
        class="space-y-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700"
    >
        <div class="flex items-start justify-between gap-3">
            <div>
                <p
                    class="text-[10px] font-black tracking-widest text-gray-400 uppercase"
                >
                    {{ listType === 'task' ? 'Task' : 'Event' }} Pass
                </p>
                <p
                    v-if="rationale"
                    class="mt-0.5 text-xs text-gray-500 dark:text-gray-400"
                >
                    {{ rationale }}
                </p>
            </div>
            <Button
                v-if="removable"
                variant="ghost"
                size="icon"
                class="h-7 w-7 shrink-0 text-gray-400 hover:text-red-500"
                @click="emit('remove')"
            >
                <X class="h-4 w-4" />
            </Button>
        </div>

        <div>
            <Label
                class="mb-1.5 block text-[11px] font-black tracking-widest text-gray-500 uppercase"
            >
                Extraction Rule
            </Label>
            <Textarea
                :model-value="extractionRule"
                rows="3"
                class="text-sm"
                placeholder="Describe what marks a record of this type in the source text, and how to read its fields from it."
                @update:model-value="
                    (v) => emit('update:extractionRule', String(v))
                "
            />
        </div>
    </div>
</template>
