<script setup lang="ts">
import ProjectEntryForm from '@/components/projects/ProjectEntryForm.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import UpgradeModal from '@/components/UpgradeModal.vue';
import { usePage } from '@inertiajs/vue3';
import { PlusIcon } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

defineProps<{
    clients: Client[];
    initialName?: string;
    triggerLabel?: string;
}>();

const emit = defineEmits<{
    success: [clientId: string];
}>();

const isOpen = ref(false);
const showUpgradeModal = ref(false);

const page = usePage<AppPageProps>();
const atLimit = computed(
    () => (page.props as any).orgMembership?.at_limit ?? {},
);

const handleTriggerClick = (e: MouseEvent) => {
    if (atLimit.value.projects) {
        e.stopPropagation();
        e.preventDefault();
        showUpgradeModal.value = true;
    } else {
        isOpen.value = true;
    }
};

const handleSuccess = (clientId: string) => {
    toast.success('Project created successfully.');
    isOpen.value = false;
    emit('success', clientId);
};
</script>

<template>
    <div>
        <div @click.capture="handleTriggerClick">
            <slot name="trigger">
                <Button class="h-11 px-6 font-bold">
                    <PlusIcon class="mr-2 h-5 w-5" />
                    {{ triggerLabel ?? 'New Project' }}
                </Button>
            </slot>
        </div>

        <Dialog v-model:open="isOpen">
            <DialogContent class="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Create Project</DialogTitle>
                    <DialogDescription>
                        Enter project details to initialize the workspace.
                    </DialogDescription>
                </DialogHeader>
                <ProjectEntryForm
                    :clients="clients"
                    :initial-name="initialName"
                    @success="handleSuccess"
                    @cancel="isOpen = false"
                />
            </DialogContent>
        </Dialog>

        <UpgradeModal
            :open="showUpgradeModal"
            limit-key="projects"
            @close="showUpgradeModal = false"
        />
    </div>
</template>
