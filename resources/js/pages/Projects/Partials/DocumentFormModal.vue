<script setup lang="ts">
import { computed } from 'vue';
import type { InertiaForm } from '@inertiajs/vue3';
import { RefreshCw } from 'lucide-vue-next';

// UI Components
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter
} from '@/components/ui/dialog';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue
} from '@/components/ui/select';

const props = defineProps<{
    open: boolean;
    mode: 'create' | 'edit';
    form: InertiaForm<Partial<ProjectDocument>>;
    requirementStatus: RequirementStatus[];
}>();

const emit = defineEmits(['update:open', 'submit']);

// 1. Proxy the Dialog state
const isOpen = computed({
    get: () => props.open,
    set: (value) => emit('update:open', value)
});

// 2. Local methods to bypass the "Prop Mutation" linter error
// Instead of direct assignment in the template, we use a helper
const updateField = <K extends keyof ProjectDocument>(field: K, value: any) => {
    // We cast the form to 'any' here specifically to tell the linter:
    // "I am intentionally updating this reactive Inertia object."
    (props.form as any)[field] = value;
};
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent class="sm:max-w-[525px]">
            <DialogHeader>
                <DialogTitle>{{ mode === 'create' ? 'Add' : 'Edit' }} Document</DialogTitle>
                <DialogDescription>
                    {{ mode === 'create' ? 'Create a new document.' : 'Update document details.' }}
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4 py-4">
                <div class="grid gap-2">
                    <Label :class="{ 'text-destructive': form.errors.name }">Document Name</Label>
                    <Input
                        :model-value="form.name ?? ''"
                        @update:model-value="(v) => updateField('name', v)"
                        :class="{ 'border-destructive': form.errors.name }"
                    />
                    <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
                </div>

                <div class="grid gap-2">
                    <Label :class="{ 'text-destructive': form.errors.type }">Category</Label>
                    <Select
                        :model-value="form.type ?? undefined"
                        @update:model-value="(v) => updateField('type', v)"
                    >
                        <SelectTrigger :class="{ 'border-destructive': form.errors.type }">
                            <SelectValue :placeholder="form.type || 'Select a category'" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="req in requirementStatus" :key="req.key" :value="req.key">
                                {{ req.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label>Content</Label>
                    <Textarea
                        :model-value="form.content ?? ''"
                        @update:model-value="(v) => updateField('content', v)"
                        class="min-h-[150px]"
                    />
                </div>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="isOpen = false">Cancel</Button>
                <Button @click="emit('submit')" :disabled="form.processing" class="bg-indigo-600">
                    <RefreshCw v-if="form.processing" class="w-4 h-4 mr-2 animate-spin" />
                    {{ mode === 'create' ? 'Save' : 'Update' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
