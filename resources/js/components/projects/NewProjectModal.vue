<script setup lang="ts">
import { ref } from 'vue';
import { PlusIcon } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import ProjectEntryForm from '@/components/projects/ProjectEntryForm.vue';
import { toast } from 'vue-sonner';

const props = defineProps<{
    clients: Client[];
    projectTypes: ProjectType[];
    initialName?: string;
    triggerLabel?: string;
}>();

const emit = defineEmits<{
    success: [clientId: string];
}>();

const isOpen = ref(false);

const handleSuccess = (clientId: string) => {
    toast.success('Project created successfully.');
    isOpen.value = false;
    emit('success', clientId);
};
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogTrigger asChild>
            <slot name="trigger">
                <Button class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold h-11 px-6 rounded-xl shadow-lg shadow-indigo-500/20 active:scale-95 transition-all">
                    <PlusIcon class="w-5 h-5 mr-2" />
                    {{ triggerLabel ?? 'New Project' }}
                </Button>
            </slot>
        </DialogTrigger>
        <DialogContent class="sm:max-w-[500px]">
            <DialogHeader>
                <DialogTitle>Create Project</DialogTitle>
                <DialogDescription>
                    Enter project details to initialize the workspace.
                </DialogDescription>
            </DialogHeader>
            <ProjectEntryForm
                :clients="clients"
                :project-types="projectTypes"
                :initial-name="initialName"
                @success="handleSuccess"
                @cancel="isOpen = false"
            />
        </DialogContent>
    </Dialog>
</template>
