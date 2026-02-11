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

// Using our strict props definition style
const { open, title, description, loading } = defineProps<{
    open: boolean;
    title?: string;
    description?: string;
    loading?: boolean;
}>();

const emit = defineEmits(['close', 'confirm']);
</script>

<template>
    <Dialog :open="open" @update:open="emit('close')">
        <DialogContent class="sm:max-w-[425px]">
            <DialogHeader>
                <DialogTitle>{{ title || 'Are you absolutely sure?' }}</DialogTitle>
                <DialogDescription>
                    {{ description || 'This action cannot be undone. This will permanently delete the record from our servers.' }}
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2 sm:gap-4">
                <Button variant="outline" @click="emit('close')" :disabled="loading">
                    Cancel
                </Button>
                <Button variant="destructive" @click="emit('confirm')" :disabled="loading">
                    <span v-if="loading">Deleting...</span>
                    <span v-else>Confirm Delete</span>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
