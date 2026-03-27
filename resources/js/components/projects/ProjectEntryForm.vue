<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectSeparator,
    SelectTrigger,
    SelectValue
} from '@/components/ui/select';
import { toast } from "vue-sonner";
import { AlertTriangle, Loader2 } from 'lucide-vue-next';
import projectRoutes from '@/routes/projects/index';
import { evaluateDescription as evaluateDescriptionRoute } from '@/actions/App/Http/Controllers/ProjectController';
import ClientEntryForm from '@/pages/Clients/Partials/ClientEntryForm.vue';

interface Props {
    clients?: Client[];
    client?: Client;
    projectTypes?: ProjectType[];
    editData?: Project;
}

const props = defineProps<Props>();
const emit = defineEmits<{
    success: [clientId: string];
    cancel: [];
}>();

const isEditing = !!props.editData;
const originalDescription = props.editData?.description ?? '';

const form = useForm({
    name: props.editData?.name || '',
    description: props.editData?.description || '',
    client_id: props.editData?.client_id || props.client?.id || '',
    project_type_id: props.editData?.project_type_id || '',
});

const evaluating = ref(false);
const descriptionQuality = ref<'good' | 'vague' | null>(
    isEditing && props.editData?.description_quality === 'vague' ? 'vague' : null
);
const descriptionSuggestions = ref<string[]>([]);

watch(() => form.description, () => {
    descriptionQuality.value = null;
    descriptionSuggestions.value = [];
});

const CREATE_NEW_SENTINEL = '__create_new__';
const showNewClientForm = ref(false);

const handleClientSelect = (value: unknown) => {
    if (!value || value === CREATE_NEW_SENTINEL) {
        showNewClientForm.value = true;
    } else {
        form.client_id = value as string;
    }
};

const handleClientCreated = (clientId: string) => {
    form.client_id = clientId;
    showNewClientForm.value = false;
};

const getCsrfToken = (): string => {
    const match = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

const doSubmit = () => {
    const url = isEditing
        ? projectRoutes.update.url(String(props.editData!.id))
        : projectRoutes.store.url();

    const method = isEditing ? 'patch' : 'post';

    form[method](url, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(isEditing ? 'Project Updated' : 'Project Created');
            emit('success', form.client_id);
        },
        onError: () => {
            toast.error('Submission failed', {
                description: 'Please check the required fields.'
            });
        }
    });
};

const submit = async () => {
    const descriptionChanged = form.description !== originalDescription;
    const needsEvaluation = form.description
        && form.client_id
        && (!isEditing || descriptionChanged)
        && descriptionQuality.value === null;

    if (!needsEvaluation) {
        doSubmit();
        return;
    }

    evaluating.value = true;

    try {
        const response = await fetch(evaluateDescriptionRoute().url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({
                description: form.description,
                client_id: form.client_id,
            }),
        });

        const data = await response.json();
        descriptionQuality.value = data.quality ?? 'good';
        descriptionSuggestions.value = data.suggestions ?? [];

        if (descriptionQuality.value === 'good') {
            doSubmit();
        }
    } catch {
        doSubmit();
    } finally {
        evaluating.value = false;
    }
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
                    :class="{ 'border-amber-400 dark:border-amber-500': descriptionQuality === 'vague' }"
                />
                <div v-if="evaluating" class="flex items-center gap-2 px-1">
                    <Loader2 class="w-3 h-3 text-indigo-400 animate-spin" />
                    <span class="text-[10px] font-bold text-indigo-400 uppercase tracking-widest">Analyzing description...</span>
                </div>
                <div v-else-if="descriptionQuality === 'vague'" class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 p-3 space-y-2">
                    <div class="flex items-center gap-2">
                        <AlertTriangle class="w-3.5 h-3.5 text-amber-500 shrink-0" />
                        <span class="text-[11px] font-bold text-amber-700 dark:text-amber-400 uppercase tracking-widest">Description needs more detail</span>
                    </div>
                    <ul v-if="descriptionSuggestions.length" class="space-y-1 pl-1">
                        <li
                            v-for="(suggestion, i) in descriptionSuggestions"
                            :key="i"
                            class="text-[11px] text-amber-700 dark:text-amber-300 flex items-start gap-1.5"
                        >
                            <span class="mt-0.5 shrink-0 text-amber-400">›</span>
                            {{ suggestion }}
                        </li>
                    </ul>
                    <p v-else class="text-[11px] text-amber-600 dark:text-amber-400 pl-1">
                        Consider adding more detail about the project's purpose, audience, or goals.
                    </p>
                </div>
            </div>

            <template v-if="!isEditing">
                <div class="grid gap-2">
                    <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                        Client Assignment
                    </Label>
                    <div v-if="client" class="h-12 flex items-center px-4 rounded-xl bg-gray-50 dark:bg-zinc-800/50 border border-gray-200 dark:border-zinc-800 font-bold text-sm text-indigo-600">
                        {{ client.company_name }}
                    </div>
                    <template v-else-if="showNewClientForm">
                        <div class="rounded-xl border border-indigo-200 dark:border-indigo-800 bg-indigo-50/50 dark:bg-indigo-950/30 p-4">
                            <p class="text-[10px] font-black uppercase tracking-widest text-indigo-500 mb-3">New Client</p>
                            <ClientEntryForm
                                :edit-data="null"
                                @success="handleClientCreated"
                                @clear-edit="showNewClientForm = false"
                            />
                        </div>
                    </template>
                    <Select v-else :model-value="form.client_id" @update:model-value="handleClientSelect">
                        <SelectTrigger class="h-12 rounded-xl border-gray-200 dark:border-gray-800 font-bold">
                            <SelectValue placeholder="Select a client..." />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="CREATE_NEW_SENTINEL" class="text-indigo-600 font-bold">
                                + Create New Client
                            </SelectItem>
                            <SelectSeparator v-if="clients?.length" />
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

            <template v-if="descriptionQuality === 'vague'">
                <Button
                    type="button"
                    variant="outline"
                    @click="descriptionQuality = null"
                    class="text-[10px] font-black uppercase tracking-widest border-gray-200 dark:border-gray-700"
                >
                    Edit Description
                </Button>
                <Button
                    type="button"
                    :disabled="form.processing"
                    @click="doSubmit"
                    class="bg-amber-500 hover:bg-amber-600 text-white font-black uppercase text-[10px] tracking-widest px-8 h-12 rounded-xl shadow-lg"
                >
                    Save Anyway
                </Button>
            </template>

            <Button
                v-else
                type="submit"
                :disabled="form.processing || evaluating"
                class="bg-indigo-600 hover:bg-indigo-700 text-white font-black uppercase text-[10px] tracking-widest px-8 h-12 rounded-xl shadow-lg"
            >
                <Loader2 v-if="evaluating" class="w-4 h-4 animate-spin" />
                <template v-else>{{ isEditing ? 'Save Changes' : 'Initialize Project' }}</template>
            </Button>
        </div>
    </form>
</template>
