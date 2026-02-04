<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue
} from '@/components/ui/select';
import { toast } from "vue-sonner";
import projectRoutes from '@/routes/projects/index';

interface Props {
    clients?: Client[];
    client?: Client;
    projectTypes?: ProjectType[];
    editData?: Project;
}

const props = defineProps<Props>();
const emit = defineEmits(['success', 'cancel']);

// Determine mode
const isEditing = !!props.editData;

const form = useForm({
    name: props.editData?.name || '',
    description: props.editData?.description || '',
    // If editing, use the ID from the project data.
    // If creating, use the client prop (if provided) or empty string.
    client_id: props.editData?.client_id || props.client?.id || '',

    // Pull the project type from the project data when editing.
    project_type_id: props.editData?.project_type_id || '',
});

const submit = () => {
    const url = isEditing
        ? projectRoutes.update.url(String(props.editData!.id))
        : projectRoutes.store.url();

    const method = isEditing ? 'patch' : 'post';

    form[method](url, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(isEditing ? 'Project updated' : 'Project initialized');
            emit('success');
        },
        onError: () => {
            toast.error('Submission failed', {
                description: 'Please check the required fields.'
            });
        }
    });
};
</script>

<template>
    <form @submit.prevent="submit" class="space-y-6">
        <div class="space-y-5">
            <div class="grid gap-2">
                <Label for="name" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                    Project Name
                </Label>
                <Input
                    id="name"
                    v-model="form.name"
                    class="h-12 rounded-xl bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-800 font-bold"
                    :class="{ 'border-red-500': form.errors.name }"
                />
            </div>
            <div class="grid gap-2">
                <Label for="description" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                    Description / Scope
                </Label>
                <Textarea
                    id="description"
                    v-model="form.description"
                    class="min-h-[100px] rounded-xl bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-800 font-medium resize-none"
                />
            </div>

            <template v-if="!isEditing">
                <div class="grid gap-2">
                    <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                        Client Assignment
                    </Label>
                    <div v-if="client" class="h-12 flex items-center px-4 rounded-xl bg-gray-50 dark:bg-zinc-800/50 border border-gray-200 dark:border-zinc-800 font-bold text-sm text-indigo-600">
                        {{ client.company_name }}
                    </div>
                    <Select v-else v-model="form.client_id">
                        <SelectTrigger class="h-12 rounded-xl border-gray-200 dark:border-gray-800 font-bold">
                            <SelectValue placeholder="Select a client..." />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="c in clients" :key="c.id" :value="c.id.toString()">
                                {{ c.company_name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                        Protocol Type
                    </Label>
                    <Select v-model="form.project_type_id">
                        <SelectTrigger class="h-12 rounded-xl border-gray-200 dark:border-gray-800 font-bold">
                            <SelectValue placeholder="Choose a protocol..." />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="type in projectTypes" :key="type.id" :value="type.id.toString()">
                                {{ type.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </template>
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
                {{ isEditing ? 'Save Changes' : 'Initialize Project' }}
            </Button>
        </div>
    </form>
</template>
