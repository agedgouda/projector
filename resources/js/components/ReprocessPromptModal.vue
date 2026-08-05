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
import { Textarea } from '@/components/ui/textarea';
import { ref, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    title?: string;
    description?: string;
    loading?: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'confirm', oneOffInstructions: string | null): void;
}>();

// Ephemeral by design — cleared whenever the dialog closes so a leftover instruction from a
// previous run never silently applies to the next one. Never bound to any persisted field.
const oneOffInstructions = ref('');

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) oneOffInstructions.value = '';
    },
);

const confirm = () => {
    emit('confirm', oneOffInstructions.value.trim() || null);
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('close')">
        <DialogContent class="sm:max-w-[480px]">
            <DialogHeader>
                <DialogTitle>{{ title || 'Reprocess Document?' }}</DialogTitle>
                <DialogDescription>
                    {{ description || "Reprocessing will regenerate this document's output, overwriting anything previously generated." }}
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-2">
                <Label class="text-[10px] font-black tracking-widest text-gray-400 uppercase">
                    Instructions for this run only (optional)
                </Label>
                <Textarea
                    v-model="oneOffInstructions"
                    placeholder="e.g. Only extract from the section labeled &quot;Action Items&quot; this time."
                    class="min-h-24 text-sm"
                />
                <p class="text-[11px] text-gray-400">
                    Applied to this reprocess only — not saved anywhere, and won't affect future runs.
                </p>
            </div>

            <DialogFooter class="gap-2 sm:gap-4">
                <Button variant="outline" @click="emit('close')" :disabled="loading">
                    No
                </Button>
                <Button @click="confirm" :disabled="loading">
                    {{ loading ? 'Please wait...' : 'Yes, Reprocess' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
