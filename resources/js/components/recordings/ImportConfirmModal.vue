<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Textarea } from '@/components/ui/textarea';
import { CUSTOM_PROMPT_MODES, type CustomPrompt, type CustomPromptMode } from '@/lib/constants';
import { ref, watch } from 'vue';

// Shared by every import source (Google Doc, uploaded file, picked meeting recording) whenever
// the picked type is Transcription — same dialog, same wording, same field, regardless of
// where the content is coming from. itemTitle is just whatever that source's own title is
// (doc title, filename, or recording title); the sentence around it never changes.
const props = defineProps<{
    open: boolean;
    itemTitle?: string;
    loading?: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'confirm', additionalInfo: CustomPrompt | null): void;
}>();

// Ephemeral — cleared whenever the dialog closes so a leftover note from a previous import
// never silently applies to the next one.
const additionalInfo = ref('');
// Add is the default: a short note ("the client is Acme") should guide the standard Meeting
// Notes, not become the entire instruction. Replace is for creating something new instead.
const mode = ref<CustomPromptMode>('add');

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            additionalInfo.value = '';
            mode.value = 'add';
        }
    },
);

const save = () => {
    const text = additionalInfo.value.trim();
    emit('confirm', text ? { text, mode: mode.value } : null);
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('close')">
        <DialogContent class="sm:max-w-[480px]">
            <DialogHeader>
                <DialogTitle>Import?</DialogTitle>
                <DialogDescription>
                    <template v-if="itemTitle">"{{ itemTitle }}" will</template>
                    <template v-else>This will</template>
                    be imported and Meeting Notes will be generated from it.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-2">
                <Label class="text-[10px] font-black tracking-widest text-gray-400 uppercase">
                    Additional Information (optional)
                </Label>
                <Textarea
                    v-model="additionalInfo"
                    :placeholder="
                        mode === 'add'
                            ? 'e.g. The client is Acme — list an owner for every action item.'
                            : 'e.g. Write a one-page client recap of the decisions made, with no internal notes.'
                    "
                    class="min-h-24 text-sm"
                />
                <RadioGroup v-if="additionalInfo.trim()" v-model="mode" class="gap-2 pt-1">
                    <div v-for="option in CUSTOM_PROMPT_MODES" :key="option.value" class="flex items-start gap-2">
                        <RadioGroupItem :id="`import-prompt-mode-${option.value}`" :value="option.value" class="mt-0.5" />
                        <Label :for="`import-prompt-mode-${option.value}`" class="flex flex-col items-start gap-0.5">
                            <span class="text-[13px] font-medium text-slate-600 dark:text-slate-300">{{ option.label }}</span>
                            <span class="text-xs font-normal text-muted-foreground">{{ option.description }}</span>
                        </Label>
                    </div>
                </RadioGroup>
            </div>

            <DialogFooter class="gap-2 sm:gap-4">
                <Button variant="outline" @click="emit('close')" :disabled="loading">
                    Cancel
                </Button>
                <Button @click="save" :disabled="loading">
                    {{ loading ? 'Importing...' : 'Save' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
