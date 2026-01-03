<script setup lang="ts">
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from 'lucide-vue-next';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import projectTypeRoutes from '@/routes/project-types/index';

const props = defineProps<{
    editData: any | null;
    iconLibrary: { name: string; component: any }[];
}>();

const emit = defineEmits(['success', 'cancel']);

const form = useForm({
    name: '',
    icon: 'Briefcase',
    document_schema: [] as any[],
});

// Sync form with editData when it changes
watch(() => props.editData, (newVal) => {
    if (newVal) {
        form.name = newVal.name;
        form.icon = newVal.icon ?? 'Briefcase';
        form.document_schema = newVal.document_schema ? [...newVal.document_schema] : [];
    } else {
        form.reset();
    }
}, { immediate: true });

const suggestKey = (index: number) => {
    const doc = form.document_schema[index];
    if (doc.key === 'intake' || !doc.label) return;
    doc.key = doc.label.toLowerCase().trim().replace(/[^a-z0-9 ]/g, '').replace(/\s+/g, '_');
};

const submit = () => {
    const hasIntake = form.document_schema.some(doc => doc.key === 'intake');
    if (!hasIntake) {
        form.document_schema.unshift({ label: 'Notes', key: 'intake', required: false });
    }

    const url = props.editData
        ? projectTypeRoutes.update.url(props.editData.id)
        : projectTypeRoutes.store.url();

    form[props.editData ? 'put' : 'post'](url, {
        onSuccess: () => emit('success'),
        preserveScroll: true
    });
};
</script>

<template>
    <form @submit.prevent="submit" class="space-y-6">
        <div class="grid grid-cols-2 gap-4">
            <div class="space-y-2">
                <Label>Type Name</Label>
                <Input v-model="form.name" placeholder="e.g. Website Development" class="rounded-xl" />
                <div v-if="form.errors.name" class="text-xs text-red-500">{{ form.errors.name }}</div>
            </div>
            <div class="space-y-2">
                <Label>Display Icon</Label>
                <div class="flex gap-2 p-1.5 border rounded-xl bg-gray-50/50 overflow-x-auto no-scrollbar">
                    <button v-for="icon in iconLibrary" :key="icon.name" type="button"
                        @click="form.icon = icon.name"
                        :class="['p-2 rounded-lg transition-all shrink-0', form.icon === icon.name ? 'bg-indigo-600 text-white shadow-md' : 'bg-white text-gray-400 border border-gray-100']"
                    >
                        <component :is="icon.component" class="w-4 h-4" />
                    </button>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <div class="flex items-center justify-between border-b pb-2">
                <Label class="font-black text-indigo-600 uppercase tracking-widest text-[10px]">Document Schema</Label>
                <Button type="button" variant="outline" size="sm" @click="form.document_schema.unshift({ label: '', key: '', required: false })" class="h-7 text-[10px] font-bold rounded-lg uppercase">
                    <Plus class="w-3 h-3 mr-1" /> Add Requirement
                </Button>
            </div>

            <div class="grid grid-cols-1 gap-3">
                <div v-for="(doc, index) in form.document_schema" :key="index"
                    class="group/row relative p-4 rounded-2xl border bg-white dark:bg-gray-900 transition-all hover:border-indigo-200"
                >
                    <button type="button" @click="form.document_schema.splice(index, 1)" class="absolute -top-2 -right-2 bg-white border shadow-sm rounded-full p-1 text-gray-400 hover:text-red-500">
                        <X class="w-3 h-3" />
                    </button>

                    <div class="grid grid-cols-12 gap-4 items-end">
                        <div class="col-span-5 space-y-1.5">
                            <span class="text-[9px] font-black uppercase text-gray-400 tracking-tighter ml-1">Label</span>
                            <Input v-model="doc.label" @input="suggestKey(index)" placeholder="e.g. Project Brief" class="h-9 rounded-lg" />
                        </div>
                        <div class="col-span-4 space-y-1.5">
                            <span class="text-[9px] font-black uppercase text-gray-400 tracking-tighter ml-1">Key</span>
                            <Input v-model="doc.key" placeholder="project_brief" class="h-9 rounded-lg font-mono text-[11px] bg-gray-50" />
                        </div>
                        <div class="col-span-3 pb-2 flex items-center justify-end">
                            <label class="flex items-center gap-2 cursor-pointer group">
                                <Checkbox v-model:checked="doc.required" />
                                <span class="text-[10px] font-bold uppercase text-gray-500 group-hover:text-indigo-600">Required</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3 pt-4 border-t">
            <Button type="button" variant="ghost" @click="emit('cancel')" class="rounded-xl font-bold">Cancel</Button>
            <Button type="submit" :disabled="form.processing" class="bg-indigo-600 hover:bg-indigo-700 rounded-xl font-black px-8">
                {{ editData ? 'Update Type' : 'Create Type' }}
            </Button>
        </div>
    </form>
</template>
