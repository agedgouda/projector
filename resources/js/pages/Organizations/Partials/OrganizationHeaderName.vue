<script setup lang="ts">
import { nextTick, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Pencil } from 'lucide-vue-next';
import { toast } from 'vue-sonner';
import organizationRoutes from '@/routes/organizations/index';

interface Props {
    organization: Organization;
}

const props = defineProps<Props>();

const isEditing = ref(false);
const inputRef = ref<HTMLInputElement | null>(null);

const form = useForm({ name: props.organization.name });

function startEditing() {
    form.name = props.organization.name;
    isEditing.value = true;
    nextTick(() => inputRef.value?.focus());
}

function cancel() {
    isEditing.value = false;
    form.reset();
    form.clearErrors();
}

function save() {
    if (form.processing) { return; }

    if (form.name === props.organization.name) {
        isEditing.value = false;
        return;
    }

    form.patch(organizationRoutes.update.url(props.organization.id), {
        preserveScroll: true,
        onSuccess: () => {
            isEditing.value = false;
            toast.success('Organization name updated');
        },
        onError: () => toast.error(form.errors.name || 'Could not update name'),
    });
}
</script>

<template>
    <div v-if="isEditing" class="grid gap-1">
        <input
            ref="inputRef"
            v-model="form.name"
            type="text"
            :disabled="form.processing"
            class="bg-transparent text-3xl font-black tracking-tighter text-gray-900 uppercase outline-none dark:text-white border-b-2 border-projector-primary-500"
            @keyup.enter="save"
            @keyup.esc="cancel"
            @blur="save"
        />
        <p v-if="form.errors.name" class="text-xs font-bold text-red-500">{{ form.errors.name }}</p>
    </div>
    <button
        v-else
        type="button"
        class="group flex items-center gap-2 text-left"
        @click="startEditing"
    >
        <h2 class="text-3xl font-black tracking-tighter text-gray-900 uppercase dark:text-white">
            {{ organization.name }}
        </h2>
        <Pencil class="h-4 w-4 text-gray-300 opacity-0 transition-opacity group-hover:opacity-100 dark:text-zinc-600" />
    </button>
</template>
