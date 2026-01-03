<script setup lang="ts">
import { useConfirmDelete } from '@/composables/useConfirmDelete';
import clientRoutes from '@/routes/clients/index';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import ClientProjects from './ClientProjects.vue'; // Ensure path is correct
import { router } from '@inertiajs/vue3';
import { formatPhoneNumber } from '@/lib/utils';
import { Button } from '@/components/ui/button';
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

                    <div
                        class="group relative flex flex-col md:flex-row md:items-center justify-between p-5 bg-white dark:bg-gray-900 border rounded-2xl transition-all duration-300 shadow-sm z-20"
                        :class="selectedClientId === client.id
                            ? 'border-indigo-500 ring-4 ring-indigo-500/5'
                            : 'border-gray-100 dark:border-gray-800 hover:border-gray-300 dark:hover:border-gray-700'"
                    >
                        <div class="flex items-start gap-4 flex-1">
                            <div class="mt-1 flex items-center justify-center w-10 h-10 rounded-xl bg-gray-50 dark:bg-gray-800 text-gray-400 group-hover:bg-indigo-50 group-hover:text-indigo-600 dark:group-hover:bg-indigo-500/10 transition-colors">
                                <Briefcase class="w-5 h-5" />
                            </div>

                            <div class="space-y-1">
                                <h3 class="font-black text-gray-900 dark:text-white tracking-tight text-lg">
                                    {{ client.company_name }}
                                </h3>
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                    <div class="flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400 font-medium">
                                        <User class="w-3.5 h-3.5 opacity-60" />
                                        {{ client.contact_name }}
                                    </div>
                                    <div class="flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400 font-mono">
                                        <Phone class="w-3.5 h-3.5 opacity-60" />
                                        {{ formatPhoneNumber(client.contact_phone) }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 mt-4 md:mt-0 ml-0 md:ml-4 pt-4 md:pt-0 border-t md:border-t-0 border-gray-50 dark:border-gray-800">

                            <Button
                                @click="emit('toggleProjects', client.id)"
                                :variant="selectedClientId === client.id ? 'default' : 'secondary'"
                                :class="[
                                    'h-10 px-5 rounded-xl text-xs font-black uppercase tracking-wider transition-all duration-200 active:scale-95',
                                    selectedClientId === client.id
                                        ? 'bg-indigo-600 hover:bg-indigo-700 text-white shadow-[0_0_20px_rgba(79,70,229,0.3)]'
                                        : 'text-gray-500 hover:text-indigo-600 hover:bg-indigo-50'
                                ]"
                            >
                                <ChevronDown
                                    class="w-4 h-4 mr-2 transition-transform duration-300"
                                    :class="{ 'rotate-180': selectedClientId === client.id }"
                                />
                                {{ selectedClientId === client.id ? 'Hide' : 'Show' }} {{ client.projects?.length === 1 ? 'Project' : 'Projects' }}
                                <span
                                    class="ml-2 px-1.5 py-0.5 rounded-md text-[10px] transition-colors"
                                    :class="selectedClientId === client.id
                                        ? 'bg-white/20 text-white'
                                        : 'bg-gray-200 dark:bg-gray-800 text-gray-600 group-hover/btn:bg-indigo-100 group-hover/btn:text-indigo-600'"
                                >
                                    {{ client.projects?.length || 0 }}
                                </span>
                            </Button>

                            <Button
                                variant="ghost"
                                size="icon"
                                @click="emit('edit', client)"
                                class="rounded-xl text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition-all"
                            >
                                <Edit2 class="w-4 h-4" />
                            </Button>

                            <Button
                                variant="ghost"
                                size="icon"
                                @click="openModal({ id: client.id, name: client.company_name })"
                                class="rounded-xl text-gray-400 hover:text-red-600 hover:bg-red-50 transition-all"
                            >
                                <Trash2 class="w-4 h-4" />
                            </Button>
                        </div>
                    </div>

                    <transition
                        enter-active-class="transition-all duration-500 ease-out"
                        leave-active-class="transition-all duration-300 ease-in"
                        enter-from-class="opacity-0 -translate-y-8 max-h-0"
                        enter-to-class="opacity-100 translate-y-0 max-h-[2000px]"
                        leave-from-class="opacity-100 translate-y-0 max-h-[2000px]"
                        leave-to-class="opacity-0 -translate-y-8 max-h-0"
                    >
                        <div v-if="selectedClientId === client.id" class="overflow-hidden">
                            <div class="pt-10 pb-8 px-6 border-x border-b border-indigo-100 dark:border-indigo-500/20 bg-gray-50/50 dark:bg-indigo-500/5 rounded-b-3xl -mt-6">
                                <div class="flex items-center gap-4 mb-8">
                                    <div class="h-px bg-indigo-200 dark:bg-indigo-500/30 flex-1"></div>
                                    <h4 class="text-[10px] font-black uppercase tracking-[0.3em] text-indigo-500 dark:text-indigo-400">
                                        Project Portfolio for {{ client.company_name }}
                                    </h4>
                                    <div class="h-px bg-indigo-200 dark:bg-indigo-500/30 flex-1"></div>
                                </div>

                                <ClientProjects
                                    :key="client.id"
                                    :client="client"
                                    :projects="client.projects"
                                    :projectTypes="projectTypes"
                                />
                            </div>
                        </div>
                    </transition>
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
