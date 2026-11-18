<script setup lang="ts">
import IndustryCombobox from '@/components/clients/IndustryCombobox.vue';
import LogoFileInput from '@/components/LogoFileInput.vue';
import LogoUpload from '@/components/LogoUpload.vue';
import PhoneInput from '@/components/PhoneInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import clientRoutes from '@/routes/clients/index';
import clientLogoRoutes from '@/routes/clients/logo/index';
import { useForm, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps<{
    editData: (Client & { logo_url?: string | null }) | null;
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
    industry: null as string | null,
    inactive: false,
    logo: null as File | null,
});

watch(
    () => props.editData,
    (newVal) => {
        if (newVal) {
            isEditing.value = true;
            form.company_name = newVal.company_name;
            form.contact_name = newVal.contact_name;
            form.contact_phone = newVal.contact_phone ?? '';
            form.email = newVal.email ?? '';
            form.industry = newVal.industry ?? null;
            form.inactive = newVal.inactive ?? false;
        } else {
            isEditing.value = false;
            form.reset();
        }
    },
    { immediate: true },
);

const resetForm = () => {
    form.reset();
    isEditing.value = false;
    emit('clear-edit');
};

const submit = () => {
    const url =
        isEditing.value && props.editData
            ? clientRoutes.update.url(props.editData.id)
            : clientRoutes.store.url();

    const method = isEditing.value ? 'patch' : 'post';

    form[method](url, {
        forceFormData: true,
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
        <LogoUpload
            v-if="isEditing && editData"
            :current-logo-url="editData.logo_url ?? null"
            :upload-url="clientLogoRoutes.store.url(editData.id)"
            :delete-url="clientLogoRoutes.destroy.url(editData.id)"
            label="Client Logo"
            class="mb-4"
        />

        <form @submit.prevent="submit" class="space-y-4">
            <LogoFileInput
                v-if="!isEditing"
                v-model="form.logo"
                label="Client Logo"
                :error="form.errors.logo"
                class="mb-2"
            />
            <div>
                <Label :class="{ 'text-destructive': form.errors.company_name }"
                    >Company Name</Label
                >
                <Input
                    v-model="form.company_name"
                    placeholder="Company Name"
                    class="w-full rounded-lg border-gray-300 focus:ring-projector-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                    required
                />
                <p
                    v-if="form.errors.company_name"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.company_name }}
                </p>
            </div>

            <div>
                <Label :class="{ 'text-destructive': form.errors.contact_name }"
                    >Contact Name</Label
                >
                <Input
                    v-model="form.contact_name"
                    placeholder="Contact Name"
                    class="w-full rounded-lg border-gray-300 focus:ring-projector-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                    required
                />
            </div>

            <div>
                <Label
                    :class="{ 'text-destructive': form.errors.contact_phone }"
                    >Contact Phone</Label
                >
                <PhoneInput
                    v-model="form.contact_phone"
                    :class="{ 'border-destructive': form.errors.contact_phone }"
                />
                <p
                    v-if="form.errors.contact_phone"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.contact_phone }}
                </p>
            </div>

            <div>
                <Label :class="{ 'text-destructive': form.errors.email }"
                    >Email</Label
                >
                <Input
                    v-model="form.email"
                    type="email"
                    placeholder="contact@example.com"
                    class="w-full rounded-lg border-gray-300 focus:ring-projector-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                />
                <p
                    v-if="form.errors.email"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.email }}
                </p>
            </div>

            <div>
                <Label :class="{ 'text-destructive': form.errors.industry }"
                    >Industry</Label
                >
                <IndustryCombobox v-model="form.industry" />
                <p
                    v-if="form.errors.industry"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.industry }}
                </p>
            </div>

            <div v-if="isEditing" class="flex items-center gap-2">
                <input
                    id="client-inactive"
                    v-model="form.inactive"
                    type="checkbox"
                    class="h-4 w-4 rounded border-gray-300 text-projector-primary-600 focus:ring-projector-primary-500"
                />
                <Label for="client-inactive" class="cursor-pointer"
                    >Inactive</Label
                >
            </div>

            <div class="flex items-center gap-2 pt-2">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-lg bg-projector-primary-600 px-4 py-2 font-bold text-white transition hover:bg-projector-primary-700 disabled:opacity-50"
                >
                    {{ isEditing ? 'Update' : 'Save' }}
                </Button>

                <Button
                    v-if="isEditing"
                    @click="resetForm"
                    type="button"
                    class="rounded-lg border border-projector-primary-600 bg-white px-4 py-2 font-bold text-projector-primary-600 transition hover:bg-projector-primary-50 dark:border-projector-primary-400 dark:bg-transparent dark:text-projector-primary-400 dark:hover:bg-projector-primary-950/30"
                >
                    Cancel
                </Button>
            </div>
        </form>
    </div>
</template>
