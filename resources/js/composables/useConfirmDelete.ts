import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';

interface DeleteOptions {
    onSuccess?: () => void;
    redirectRoute?: string;
}

export function useConfirmDelete() {
    const isModalOpen = ref(false);
    const itemToDelete = ref<{ id: string; name: string } | null>(null);
    const deleteForm = useForm({});

    const openModal = (item: { id: string; name: string }) => {
        itemToDelete.value = item;
        isModalOpen.value = true;
    };

    const closeModal = () => {
        isModalOpen.value = false;
        itemToDelete.value = null;
    };

    const executeDelete = (url: string, options: DeleteOptions = {}) => {
        if (!itemToDelete.value) return;

        deleteForm.delete(url, {
            onSuccess: () => {
                closeModal();
                if (options.onSuccess) options.onSuccess();
                if (options.redirectRoute) {
                    router.get(options.redirectRoute);
                }
            },
        });
    };

    return {
        isModalOpen,
        itemToDelete,
        deleteForm,
        openModal,
        closeModal,
        executeDelete,
    };
}
