<script setup lang="ts">
import { Plus, X, ArrowRight } from 'lucide-vue-next';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useProjectTypeForm } from '@/composables/useProjectTypeForm'; // Adjust path as needed

const props = defineProps<{
    editData: any | null;
    iconLibrary: { name: string; component: any }[];
    aiTemplates: { id: string, name: string }[];
}>();

const emit = defineEmits(['success', 'cancel']);

// Use the new composable
const {
    form,
    submit,
    addSchemaItem,
    addWorkflowStep,
    suggestKey
} = useProjectTypeForm(
    props.editData,
    () => emit('success')
);
</script>

<template>
    <form @submit.prevent="submit" class="space-y-10 px-10">
        <div class="space-y-10 max-w-2xl">
            <div class="space-y-4">
                <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">Name</Label>
                <Input v-model="form.name" placeholder="e.g. Enterprise SaaS Workflow" class="rounded-xl h-12 bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-800 text-lg font-bold focus:ring-4 focus:ring-indigo-500/5 transition-all" />
            </div>

            <div class="space-y-4">
                <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">Icon</Label>
                <div class="grid grid-cols-5 sm:grid-cols-8 lg:grid-cols-10 gap-2 p-3 border border-gray-200 dark:border-gray-800 rounded-xl bg-white dark:bg-gray-950 w-full">
                    <button v-for="icon in iconLibrary" :key="icon.name" type="button"
                        @click="form.icon = icon.name"
                        :class="['p-3 rounded-lg transition-all flex items-center justify-center', form.icon === icon.name ? 'bg-indigo-600 text-white shadow-md' : 'text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800']"
                    >
                        <component :is="icon.component" class="w-4 h-4" />
                    </button>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between px-1 max-w-2xl">
            <h3 class="text-[10px] font-black uppercase tracking-widest text-gray-400">Document Definitions</h3>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                @click="addSchemaItem"
                class="h-6 px-2 text-[9px] font-black rounded uppercase text-indigo-600 hover:bg-indigo-50"
            >
                <Plus class="w-3 h-3 mr-1" /> Add Definition
            </Button>
        </div>

        <div class="border border-gray-100 dark:border-gray-800 rounded-xl overflow-hidden bg-white dark:bg-gray-950">
            <div class="flex items-center px-4 py-2 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-900/50">
                <div class="w-12 shrink-0 text-[9px] font-black uppercase text-gray-400 flex justify-center">Task</div>
                <div class="w-72 shrink-0 px-4 text-[9px] font-black uppercase text-gray-400 border-l border-gray-200/50 dark:border-gray-700/50">Label</div>
                <div class="flex-1 px-6 text-[9px] font-black uppercase text-gray-400 border-l border-gray-200/50 dark:border-gray-700/50">System Key</div>
                <div class="w-8 shrink-0"></div>
            </div>

            <div v-for="(doc, index) in form.document_schema" :key="index" class="flex items-center px-4 py-1 border-b border-gray-100 dark:border-gray-800 last:border-0 hover:bg-gray-50/50 dark:hover:bg-gray-900/50 transition-colors">
                <div class="w-12 shrink-0 flex justify-center">
                    <input type="checkbox" v-model="doc.is_task" class="w-3.5 h-3.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:bg-gray-900 dark:border-gray-700 cursor-pointer" />
                </div>
                <div class="w-72 shrink-0 border-l border-gray-100 dark:border-gray-800 px-4">
                    <Input v-model="doc.label" @input="suggestKey(index)" placeholder="e.g. Technical Specification" class="h-9 border-none shadow-none focus-visible:ring-0 px-0 bg-transparent text-sm text-gray-900 dark:text-gray-100" />
                </div>
                <div class="flex-1 border-l border-gray-100 dark:border-gray-800 px-1 flex items-center">
                    <Input v-model="doc.key" class="h-9 pl-5 border-none shadow-none focus-visible:ring-0 bg-transparent font-mono text-[11px] text-gray-900 dark:text-gray-100" :disabled="doc.key === 'intake'" />
                </div>
                <div class="shrink-0">
                    <Button v-if="doc.key !== 'intake'" type="button" variant="ghost" size="icon" @click="form.document_schema.splice(index, 1)" class="h-8 w-8 text-gray-300 hover:text-red-500 transition-colors">
                        <X class="w-4 h-4" />
                    </Button>
                    <div v-else class="w-8 h-8"></div>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between px-1 max-w-2xl">
            <h3 class="text-[10px] font-black uppercase tracking-widest text-gray-400">AI Pipelines</h3>
            <Button type="button" variant="ghost" size="sm" @click="addWorkflowStep" class="h-6 px-2 text-[9px] font-black rounded uppercase text-indigo-600 hover:bg-indigo-50">
                <Plus class="w-3 h-3 mr-1" /> Add Step
            </Button>
        </div>

        <div class="border border-gray-100 dark:border-gray-800 rounded-xl overflow-hidden bg-white dark:bg-gray-950">
            <div v-for="(step, index) in form.workflow" :key="index" class="flex items-center px-4 py-1.5 border-b border-gray-100 dark:border-gray-800 last:border-0 hover:bg-gray-50/50 dark:hover:bg-gray-900/50 transition-colors gap-4">
                <div class="w-48 shrink-0 flex items-center gap-2">
                    <Label class="text-[9px] font-black uppercase text-gray-400 w-8 shrink-0">From</Label>
                    <select v-model="step.from_key" class="h-8 w-full bg-transparent border-none shadow-none text-[11px] outline-none text-gray-900 dark:text-gray-100 appearance-none cursor-pointer">
                        <option value="" disabled>Select</option>
                        <option v-for="s in form.document_schema" :key="s.key" :value="s.key">{{ s.label }}</option>
                    </select>
                </div>
                <ArrowRight class="w-3 h-3 text-indigo-400 shrink-0" />
                <div class="w-48 shrink-0 flex items-center gap-2">
                    <Label class="text-[9px] font-black uppercase text-gray-400 w-6 shrink-0">To</Label>
                    <select v-model="step.to_key" class="h-8 w-full bg-transparent border-none shadow-none text-[11px] outline-none text-gray-900 dark:text-gray-100 appearance-none cursor-pointer">
                        <option value="" disabled>Select</option>
                        <option v-for="s in form.document_schema" :key="s.key" :value="s.key">{{ s.label }}</option>
                    </select>
                </div>
                <div class="flex-1 flex items-center gap-3 border-l border-gray-100 dark:border-gray-800 pl-4">
                    <select v-model="step.ai_template_id" class="h-8 w-full bg-transparent border-none shadow-none text-[11px] outline-none text-gray-600 dark:text-gray-400 appearance-none cursor-pointer">
                        <option :value="null">Manual Processing</option>
                        <option v-for="temp in aiTemplates" :key="temp.id" :value="temp.id">{{ temp.name }}</option>
                    </select>
                </div>
                <Button type="button" variant="ghost" size="icon" @click="form.workflow.splice(index, 1)" class="h-8 w-8 text-gray-300 hover:text-red-500 transition-colors shrink-0">
                    <X class="w-4 h-4" />
                </Button>
            </div>
        </div>

        <div class="flex items-center justify-between pt-6 border-t border-gray-100 dark:border-gray-800  max-w-2xl">
            <div class="flex-1"></div>

            <div class="flex items-center gap-2">
                <Button type="button" variant="ghost" @click="emit('cancel')" class="h-9 px-4 text-[9px] font-black uppercase tracking-widest text-gray-400">
                    Cancel
                </Button>
                <Button
                    type="submit"
                    :disabled="form.processing"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-black px-6 h-9 uppercase text-[9px] tracking-widest shadow-sm transition-all active:scale-95"
                >
                    {{ form.processing ? 'Saving...' : (editData ? 'Save Changes' : 'Create Protocol') }}
                </Button>
            </div>
        </div>
    </form>
</template>
