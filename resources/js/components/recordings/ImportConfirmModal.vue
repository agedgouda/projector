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
    recordingTitle?: string;
    loading?: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'confirm', additionalInfo: string | null): void;
}>();

// Ephemeral — cleared whenever the dialog closes so a leftover note from a previous import
// never silently applies to the next one.
const additionalInfo = ref('');

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) additionalInfo.value = '';
    },
);

const save = () => {
    emit('confirm', additionalInfo.value.trim() || null);
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('close')">
        <DialogContent class="sm:max-w-[480px]">
            <DialogHeader>
                <DialogTitle>Import Recording?</DialogTitle>
                <DialogDescription>
                    <template v-if="recordingTitle">"{{ recordingTitle }}" will</template>
                    <template v-else>This recording will</template>
                    be imported and Meeting Notes will be generated from the transcript.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-2">
                <Label class="text-[10px] font-black tracking-widest text-gray-400 uppercase">
                    Additional Information (optional)
                </Label>
                <Textarea
                    v-model="additionalInfo"
                    placeholder="Anything the system should know before generating Meeting Notes from this transcript..."
                    class="min-h-24 text-sm"
                />
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
