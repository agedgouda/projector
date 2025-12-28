<script setup lang="ts">
import { useConfirmDelete } from '@/composables/useConfirmDelete';
import clientRoutes from '@/routes/clients/index';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import { router } from '@inertiajs/vue3';

// We use defineProps directly since we only need these for the template
const props = defineProps<{
    clients: any[];
    selectedClientId: string | null;
}>();

const emit = defineEmits(['toggleProjects', 'edit']);

// Pull in the delete logic
const {
    isModalOpen,
    itemToDelete,
    deleteForm,
    openModal,
    closeModal,
    executeDelete
} = useConfirmDelete();

const handleDelete = () => {
    if (!itemToDelete.value) return;

    const idToDelete = itemToDelete.value.id;

    executeDelete(clientRoutes.destroy.url(idToDelete), {
        onSuccess: () => {
            // If we just deleted the client that was currently "open",
            // redirect the user back to the main index list
            if (props.selectedClientId === idToDelete) {
                router.get(clientRoutes.index.url());
            }
        }
    });
};
</script>

<template>
    <div class="md:col-span-2 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden shadow-sm">
        <table class="w-full text-left border-collapse">
            <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-500 text-xs uppercase font-bold tracking-wider">
                <tr>
                    <th class="p-4 border-b dark:border-gray-700">Company</th>
                    <th class="p-4 border-b dark:border-gray-700">Contact</th>
                    <th class="p-4 border-b dark:border-gray-700"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                <tr v-for="client in clients" :key="client.id"
                    :class="{'bg-indigo-50/50 dark:bg-indigo-900/20': selectedClientId === client.id}"
                    class="hover:bg-gray-50 dark:hover:bg-gray-900/40 transition"
                >
                    <td class="p-4 font-bold text-gray-900 dark:text-white">
                        {{ client.company_name }}
                    </td>
                    <td class="p-4 text-sm text-gray-500 dark:text-gray-400">
                        <div class="font-medium text-gray-700 dark:text-gray-300">
                            {{ client.contact_name }}
                        </div>
                        <div>{{ client.contact_phone }}</div>
                    </td>
                    <td class="p-4 text-right space-x-3 whitespace-nowrap">
                        <button
                            @click="emit('toggleProjects', client.id)"
                            class="text-sm font-bold transition"
                            :class="selectedClientId === client.id ? 'text-indigo-600' : 'text-gray-400 hover:text-gray-600'"
                        >
                            {{ selectedClientId === client.id ? 'Hide Projects' : 'Projects' }}
                        </button>

                        <button
                            @click="emit('edit', client)"
                            class="text-indigo-600 hover:text-indigo-500 font-bold transition"
                        >
                            Edit
                        </button>

                        <button
                            @click="openModal({ id: client.id, name: client.company_name })"
                            class="text-red-600 hover:text-red-500 font-bold transition"
                        >
                            Delete
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>

        <div v-if="clients.length === 0" class="p-12 text-center">
            <p class="text-gray-500 dark:text-gray-400 text-sm">No clients found.</p>
        </div>

        <ConfirmDeleteModal
            :open="isModalOpen"
            :title="`Delete ${itemToDelete?.name}?`"
            description="Are you sure you want to delete this client? All associated projects will be affected."
            :loading="deleteForm.processing"
            @close="closeModal"
            @confirm="handleDelete"
        />
    </div>
</template>
