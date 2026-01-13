<script setup lang="ts">
import { ref } from 'vue';
import DocumentItem from '@/components/documents/DocumentItem.vue';
import DocumentFormModal from '@/components/documents/DocumentFormModal.vue';
import { useDocumentActions } from '@/composables/useDocumentActions';

const props = defineProps<{
    documents: ProjectDocument[];
}>();

const currentDocUsers = ref<User[]>([]);

const expandedDocId = ref<string | null>(null);

// This handles all the "Save", "Edit", and "Delete" logic for this specific list
const {
    form,
    isEditModalOpen,
    openEditModal,
    updateDocument
} = useDocumentActions(props);

const handleEditClick = (doc: any) => {
    // Before opening the modal, set the user list to the document's client users
    currentDocUsers.value = doc.project?.client?.users || [];

    // Now open the modal using your existing composable logic
    openEditModal(doc);
};

const toggleExpand = (id: string) => {
    expandedDocId.value = expandedDocId.value === id ? null : id;
};
</script>

<template>
    <div class="space-y-2">
        <ul class="space-y-2">
            <DocumentItem
                v-for="doc in documents"
                :key="doc.id"
                :doc="doc"
                :is-expanded="expandedDocId === doc.id"
                @toggle="toggleExpand(doc.id)"
                @edit="handleEditClick"
            />
        </ul>

        <DocumentFormModal
            v-model:open="isEditModalOpen"
            mode="edit"
            :form="form"
            :users="currentDocUsers"
            @submit="updateDocument"
        />
    </div>
</template>
