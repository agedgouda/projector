<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import clientRoutes from '@/routes/clients/index';
import { usePhoneFormatting } from '@/composables/usePhoneFormatting';

// We define 'editData' as the prop. When this is null, we are in "Create" mode.
// When this has a client object, we are in "Edit" mode.
const props = defineProps<{
    editData: any | null;
}>();

const emit = defineEmits(['clear-edit']);

const { handlePhoneInput } = usePhoneFormatting();
const isEditing = ref(false);

// The form now lives INSIDE the partial
const form = useForm({
    company_name: '',
    contact_name: '',
    contact_phone: '',
});

// Watch for the parent changing the 'editData' prop
watch(() => props.editData, (newVal) => {
    if (newVal) {
        isEditing.value = true;
        form.company_name = newVal.company_name;
        form.contact_name = newVal.contact_name;
        form.contact_phone = newVal.contact_phone;
    } else {
        isEditing.value = false;
        form.reset();
    }
}, { immediate: true });

const resetForm = () => {
    form.reset();
    isEditing.value = false;
    emit('clear-edit'); // Tell parent we are no longer editing
};

const submit = () => {
    const url = isEditing.value && props.editData
        ? clientRoutes.update.url(props.editData.id)
        : clientRoutes.store.url();

    const method = isEditing.value ? 'patch' : 'post';

    form[method](url, {
        onSuccess: () => resetForm(),
        preserveScroll: true,
    });
};

const onPhoneInput = (e: Event) => {
    handlePhoneInput(e, (val) => form.contact_phone = val);
};
</script>

<template>
    <div class="bg-white dark:bg-gray-800 p-6 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm h-fit">
        <h3 class="font-bold text-lg text-gray-900 dark:text-white mb-4">
            {{ isEditing ? 'Update Client' : 'New Client' }}
        </h3>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <input
                    v-model="form.company_name"
                    placeholder="Company Name"
                    class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-white focus:ring-indigo-500"
                    required
                />
            </div>

            <div>
                <input
                    v-model="form.contact_name"
                    placeholder="Contact Name"
                    class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-white focus:ring-indigo-500"
                    required
                />
            </div>

            <div>
                <input
                    :value="form.contact_phone"
                    @input="onPhoneInput"
                    type="text"
                    maxlength="14"
                    placeholder="(###) ###-####"
                    class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-white focus:ring-indigo-500"
                />
            </div>

            <div class="flex gap-2 pt-2 items-center">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="bg-indigo-600 text-white px-4 py-2 rounded-lg font-bold hover:bg-indigo-700 transition disabled:opacity-50"
                >
                    {{ isEditing ? 'Update' : 'Save' }}
                </button>

                <button
                    v-if="isEditing"
                    @click="resetForm"
                    type="button"
                    class="text-sm text-gray-400 hover:text-gray-600 transition"
                >
                    Cancel
                </button>
            </div>
        </form>
    </div>
</template>
