import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';

interface DeleteOptions {
    onSuccess?: () => void;
    onError?: (errors: any) => void; // Added this
    onFinish?: () => void;           // Added this
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
        // Small timeout to prevent the text from disappearing
        // while the modal is still visually fading out
        setTimeout(() => {
            itemToDelete.value = null;
            deleteForm.clearErrors();
        }, 200);
    };

    const executeDelete = (url: string, options: DeleteOptions = {}) => {
        if (!itemToDelete.value) return;

        deleteForm.delete(url, {
            preserveScroll: true,
            onSuccess: () => {
                closeModal();
                if (options.onSuccess) options.onSuccess();
                if (options.redirectRoute) {
                    router.get(options.redirectRoute);
                }
            },
            onError: (errors) => {
                // This allows the modal to stay open so the
                // user can see the validation error from the controller
                if (options.onError) options.onError(errors);
            },
            onFinish: () => {
                if (options.onFinish) options.onFinish();
            }
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
