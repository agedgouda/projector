<script setup lang="ts">
import { useConfirmDelete } from '@/composables/useConfirmDelete';
import clientRoutes from '@/routes/clients/index';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import ClientProjects from './ClientProjects.vue';
import FlatRow from '@/components/FlatRow.vue';
import IconTile from '@/components/IconTile.vue';
import { FLAT_ACTION_BUTTON } from '@/lib/flat-ui';
import { router } from '@inertiajs/vue3';
import { formatPhoneNumber } from '@/lib/utils';
import { Briefcase, User, Phone, Edit2, Trash2, ChevronDown } from 'lucide-vue-next';

const props = defineProps<{
    clients: any[];
    projectTypes: any[]; // Required for the nested ClientProjects
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
            if (props.selectedClientId === idToDelete) {
                router.get(clientRoutes.index.url());
            }
        }
    });
};
</script>

<template>
    <div class="w-full">
        <div class="relative w-full">
            <div v-if="clients.length === 0" class="text-center py-20 border-2 border-dashed rounded-3xl border-gray-100 dark:border-gray-800/50">
                <p class="text-gray-400 font-medium">No clients found in your records.</p>
            </div>

            <TransitionGroup name="list" tag="div" class="space-y-4 relative">
                <div v-for="client in clients" :key="client.id" class="w-full">

                    <FlatRow height="md" :selected="selectedClientId === client.id" clickable @click="emit('toggleProjects', client.id)">
                        <template #leading>
                            <ChevronDown
                                class="w-4 h-4 text-slate-400 transition-transform duration-300 shrink-0"
                                :class="{ 'rotate-180': selectedClientId === client.id }"
                            />
                            <IconTile :icon="Briefcase" size="sm" />
                        </template>

                        <div class="flex flex-col min-w-0">
                            <span class="font-bold text-[13px] text-slate-900 dark:text-slate-100 flex items-center gap-2 truncate">
                                {{ client.company_name }}
                                <span v-if="client.inactive" class="text-[9px] font-black uppercase tracking-widest text-slate-400 border border-slate-200 dark:border-slate-700 px-1.5 py-0.5 rounded shrink-0">
                                    Inactive
                                </span>
                            </span>
                            <span class="flex items-center gap-3 text-[11px] text-slate-400 truncate">
                                <span class="flex items-center gap-1.5">
                                    <User class="w-3 h-3 opacity-60" />
                                    {{ client.contact_name }}
                                </span>
                                <span class="hidden lg:flex items-center gap-1.5 font-mono">
                                    <Phone class="w-3 h-3 opacity-60" />
                                    {{ formatPhoneNumber(client.contact_phone) }}
                                </span>
                            </span>
                        </div>

                        <template #trailing>
                            <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">
                                {{ client.projects?.length || 0 }} {{ client.projects?.length === 1 ? 'Project' : 'Projects' }}
                            </span>
                        </template>

                        <template #actions>
                            <button type="button" @click.stop="emit('edit', client)" :class="FLAT_ACTION_BUTTON" title="Edit client">
                                <Edit2 class="w-3.5 h-3.5" />
                            </button>
                            <button
                                type="button"
                                @click.stop="openModal({ id: client.id, name: client.company_name })"
                                class="h-7 w-7 flex items-center justify-center rounded-md text-slate-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 opacity-0 group-hover:opacity-100 transition-colors"
                                title="Delete client"
                            >
                                <Trash2 class="w-3.5 h-3.5" />
                            </button>
                        </template>
                    </FlatRow>

                    <div v-if="selectedClientId === client.id" class="relative pl-7">
                        <div class="absolute left-[14px] top-0 bottom-0 w-px bg-slate-200 dark:bg-slate-800"></div>
                        <div class="py-4 pr-2">
                            <h4 class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 mb-4">
                                Project Portfolio for {{ client.company_name }}
                            </h4>

                            <ClientProjects
                                :key="client.id"
                                :client="client"
                                :projects="client.projects"
                                :projectTypes="projectTypes"
                            />
                        </div>
                    </div>
                </div>
            </TransitionGroup>
        </div>

        <ConfirmDeleteModal
            :open="isModalOpen"
            :title="`Delete ${itemToDelete?.name}?`"
            description="Warning: Deleting this client will affect all associated project history. This action cannot be undone."
            :loading="deleteForm.processing"
            @close="closeModal"
            @confirm="handleDelete"
        />
    </div>
</template>

<style scoped>
/* Ensure the list animations are smooth */
.list-move,
.list-enter-active,
.list-leave-active {
    transition: all 0.5s ease;
}
.list-enter-from,
.list-leave-to {
    opacity: 0;
    transform: translateX(30px);
}
.list-leave-active {
    position: absolute;
}
</style>
