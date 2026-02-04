<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { toast } from "vue-sonner";
import organizationRoutes from '@/routes/organizations/index';

interface Props {
    organization?: Organization;
}

const props = defineProps<Props>();
const emit = defineEmits(['success', 'cancel']);

const isEditing = !!props.organization;

const form = useForm({
    name: props.organization?.name || '',
});

const submit = () => {
    const url = isEditing
        ? organizationRoutes.update.url(props.organization!.id)
        : organizationRoutes.store.url();

    const method = isEditing ? 'patch' : 'post';

    form[method](url, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(isEditing ? 'Organization updated' : 'Organization created');
            emit('success');
        },
        onError: () => {
            toast.error('Submission failed', {
                description: form.errors.name || 'Check required fields.'
            });
        }
    });
};
</script>

<template>
    <form @submit.prevent="submit" class="space-y-6">
        <div class="grid gap-2">
            <Label for="name" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                Organization Name
            </Label>
            <Input
                id="name"
                v-model="form.name"
                class="h-12 rounded-xl bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-800 font-bold"
                :class="{ 'border-red-500': form.errors.name }"
            />
            <div v-if="form.errors.name" class="text-[10px] text-red-500 font-bold px-1 uppercase tracking-tight">
                {{ form.errors.name }}
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 pt-6 border-t border-gray-100 dark:border-gray-800">
            <Button type="button" variant="ghost" @click="emit('cancel')" class="text-[10px] font-black uppercase tracking-widest text-gray-400">
                Cancel
            </Button>
            <Button
                type="submit"
                :disabled="form.processing"
                class="bg-indigo-600 hover:bg-indigo-700 text-white font-black uppercase text-[10px] tracking-widest px-8 h-12 rounded-xl shadow-lg"
            >
                {{ isEditing ? 'Save Changes' : 'Create Organization' }}
            </Button>
        </div>
    </form>
</template>
