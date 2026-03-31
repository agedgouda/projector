<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import clientRoutes from '@/routes/clients/index';
import PhoneInput from '@/components/PhoneInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    editData: Client | null;
}>();

const emit = defineEmits<{
    'clear-edit': [];
    success: [clientId: string];
}>();

const page = usePage<AppPageProps>();

const isEditing = ref(false);

const form = useForm({
    company_name: '',
    contact_name: '',
    contact_phone: '',
    email: '',
});

watch(() => props.editData, (newVal) => {
    if (newVal) {
        isEditing.value = true;
        form.company_name = newVal.company_name;
        form.contact_name = newVal.contact_name;
        form.contact_phone = newVal.contact_phone ?? '';
        form.email = newVal.email ?? '';
    } else {
        isEditing.value = false;
        form.reset();
    }
}, { immediate: true });

const resetForm = () => {
    form.reset();
    isEditing.value = false;
    emit('clear-edit');
};

const submit = () => {
    const url = isEditing.value && props.editData
        ? clientRoutes.update.url(props.editData.id)
        : clientRoutes.store.url();

    const method = isEditing.value ? 'patch' : 'post';

    form[method](url, {
        preserveScroll: true,
        onSuccess: () => {
            const newClientId = page.props.flash?.newClientId;
            if (newClientId) {
                emit('success', String(newClientId));
            }
            resetForm();
        },
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
                <p v-if="form.errors.company_name" class="text-destructive text-xs mt-1">{{ form.errors.company_name }}</p>
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
                <p v-if="form.errors.contact_phone" class="text-destructive text-xs mt-1">{{ form.errors.contact_phone }}</p>
            </div>

            <div>
                <Label :class="{ 'text-destructive': form.errors.email }">Email</Label>
                <Input
                    v-model="form.email"
                    type="email"
                    placeholder="contact@example.com"
                    class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-white focus:ring-indigo-500"
                />
                <p v-if="form.errors.email" class="text-destructive text-xs mt-1">{{ form.errors.email }}</p>
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
