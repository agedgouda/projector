<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { documentTypeLabel } from '@/lib/documentTypes';
import { X } from 'lucide-vue-next';
import { computed } from 'vue';

// The text-source counterpart to ImportTransformationPassEditor.vue — there's no column mapping
// here, just a plain-English extraction_rule the user can read and tighten, since there are no
// columns/rows to point at. See ImportTransformationModal.vue, which renders one of these per
// detected/saved pass when the source is text rather than a spreadsheet.
//
// listType isn't locked to the AI's task/event guess: typeOptions lists every type in the
// project's own document catalog too, so a reviewer can override the guess and file the whole
// source text as a plain project document instead (Meeting Notes, Transcription, etc.) — see
// ImportTransformationController::applyText(), which skips extraction entirely for those.
const props = defineProps<{
    listType: string;
    extractionRule: string;
    rationale?: string | null;
    removable?: boolean;
    typeOptions?: DocumentSchemaItem[];
}>();

const emit = defineEmits<{
    (e: 'update:extractionRule', value: string): void;
    (e: 'update:listType', value: string): void;
    (e: 'remove'): void;
}>();

const isExtractable = computed(
    () => props.listType === 'task' || props.listType === 'event',
);

const currentLabel = computed(() =>
    documentTypeLabel(props.listType, props.typeOptions ?? []),
);
</script>

<template>
    <div
        class="space-y-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <div
                    v-if="typeOptions && typeOptions.length > 0"
                    class="flex items-center gap-2"
                >
                    <Label
                        class="text-[10px] font-black tracking-widest text-gray-400 uppercase"
                    >
                        File As
                    </Label>
                    <Select
                        :model-value="listType"
                        @update:model-value="
                            (v) => emit('update:listType', String(v))
                        "
                    >
                        <SelectTrigger class="h-7 w-auto text-xs">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in typeOptions"
                                :key="option.key"
                                :value="option.key"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <p
                    v-else
                    class="text-[10px] font-black tracking-widest text-gray-400 uppercase"
                >
                    {{ currentLabel }} Pass
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

        <div v-if="isExtractable">
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
        <p v-else class="text-sm text-gray-500 dark:text-gray-400">
            The full document text will be saved as this
            {{ currentLabel }}'s content — no extraction happens for this type.
        </p>
    </div>
</template>
