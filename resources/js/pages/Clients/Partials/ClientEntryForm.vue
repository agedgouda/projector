<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import clientRoutes from '@/routes/clients/index';
import PhoneInput from '@/components/PhoneInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

// We define 'editData' as the prop. When this is null, we are in "Create" mode.
// When this has a client object, we are in "Edit" mode.
const props = defineProps<{
    editData: any | null;
}>();

const emit = defineEmits(['clear-edit']);

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

</script>

<template>
    <div>
        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <Label :class="{ 'text-destructive': form.errors.company_name }">Company Name</Label>
                <Input
                    v-model="form.company_name"
                    placeholder="Company Name"
                    class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-white focus:ring-indigo-500"
                    required
                />
            </div>

            <div>
                <Label :class="{ 'text-destructive': form.errors.contact_name }">Contact Name</Label>
                <Input
                    v-model="form.contact_name"
                    placeholder="Contact Name"
                    class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-white focus:ring-indigo-500"
                    required
                />
            </div>

            <div>
                <Label :class="{ 'text-destructive': form.errors.contact_phone }">Contact Phone</Label>
                <PhoneInput
                    v-model="form.contact_phone"
                    :class="{ 'border-destructive': form.errors.contact_phone }"
                />
            </div>

            <div class="flex gap-2 pt-2 items-center">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    class="bg-indigo-600 text-white px-4 py-2 rounded-lg font-bold hover:bg-indigo-700 transition disabled:opacity-50"
                >
                    {{ isEditing ? 'Update' : 'Save' }}
                </Button>

                <Button
                    v-if="isEditing"
                    @click="resetForm"
                    type="button"
                    class="text-sm text-gray-400 hover:text-gray-600 transition"
                >
                    Cancel
                </Button>
            </div>
        </form>
    </div>
</template>
