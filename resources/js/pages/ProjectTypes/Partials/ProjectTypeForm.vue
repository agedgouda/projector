<script setup lang="ts">
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, X, Sparkles, ArrowRight, FileText, Zap, Terminal, Hash } from 'lucide-vue-next';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import projectTypeRoutes from '@/routes/project-types/index';

const props = defineProps<{
    editData: any | null;
    iconLibrary: { name: string; component: any }[];
    aiTemplates: { id: string, name: string }[];
}>();

const emit = defineEmits(['success', 'cancel']);

const activeTab = ref('documents');

const form = useForm({
    name: '',
    icon: 'Briefcase',
    document_schema: [] as any[],
    workflow: [] as { step: number, from_key: string, to_key: string, ai_template_id: string | null }[],
});

watch(() => props.editData, (newVal) => {
    if (newVal) {
        form.name = newVal.name;
        form.icon = newVal.icon ?? 'Briefcase';
        form.document_schema = newVal.document_schema ? [...newVal.document_schema] : [];
        form.workflow = newVal.workflow ? [...newVal.workflow] : [];
    } else {
        form.reset();
        form.document_schema = [{ label: 'Notes', key: 'intake', required: true }];
    }
}, { immediate: true });

const suggestKey = (index: number) => {
    const doc = form.document_schema[index];
    if (doc.key === 'intake' || !doc.label) return;
    doc.key = doc.label.toLowerCase().trim().replace(/[^a-z0-9 ]/g, '').replace(/\s+/g, '_');
};

const addWorkflowStep = () => {
    form.workflow.push({
        step: form.workflow.length + 1,
        from_key: '',
        to_key: '',
        ai_template_id: null
    });
};

const submit = () => {
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
    <form @submit.prevent="submit" class="space-y-8">

        <div class="bg-gray-50/50 dark:bg-gray-900/50 p-6 rounded-3xl border border-gray-100 dark:border-gray-800 space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="space-y-2">
                    <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1 flex items-center gap-2">
                        <Terminal class="w-3 h-3 text-indigo-500" /> Protocol Identity
                    </Label>
                    <Input v-model="form.name" placeholder="e.g. Software Development" class="rounded-xl h-12 bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-800 font-bold focus:ring-4 focus:ring-indigo-500/5" />
                </div>
                <div class="space-y-2">
                    <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">Display Icon</Label>
                    <div class="flex gap-2 p-1.5 border border-gray-200 dark:border-gray-800 rounded-xl bg-white dark:bg-gray-950 overflow-x-auto no-scrollbar">
                        <button v-for="icon in iconLibrary" :key="icon.name" type="button"
                            @click="form.icon = icon.name"
                            :class="['p-2.5 rounded-lg transition-all shrink-0', form.icon === icon.name ? 'bg-indigo-600 text-white shadow-md' : 'text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800']"
                        >
                            <component :is="icon.component" class="w-4 h-4" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex border-b border-gray-100 dark:border-gray-800 p-1 bg-gray-50/50 dark:bg-gray-900/50 rounded-2xl w-fit">
            <button type="button" @click="activeTab = 'documents'"
                :class="['px-8 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-[0.2em] transition-all flex items-center gap-2',
                    activeTab === 'documents' ? 'bg-white dark:bg-gray-800 text-indigo-600 shadow-sm border border-gray-100 dark:border-gray-700' : 'text-gray-400 hover:text-gray-600']"
            >
                <FileText class="w-3.5 h-3.5" /> Document Definitions
            </button>
            <button type="button" @click="activeTab = 'pipeline'"
                :class="['px-8 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-[0.2em] transition-all flex items-center gap-2',
                    activeTab === 'pipeline' ? 'bg-white dark:bg-gray-800 text-indigo-600 shadow-sm border border-gray-100 dark:border-gray-700' : 'text-gray-400 hover:text-gray-600']"
            >
                <Zap class="w-3.5 h-3.5" /> AI Pipeline Logic
            </button>
        </div>

        <div v-if="activeTab === 'documents'" class="space-y-4 animate-in fade-in duration-300">
            <div class="flex items-center justify-between mb-2">
                <p class="text-[11px] text-gray-400 font-bold uppercase tracking-widest px-1">Schema Configuration</p>
                <Button type="button" variant="ghost" size="sm" @click="form.document_schema.push({ label: '', key: '', required: false })" class="h-8 text-[10px] font-black rounded-lg uppercase text-indigo-600">
                    <Plus class="w-3 h-3 mr-2" /> Add Definition
                </Button>
            </div>

            <div v-for="(doc, index) in form.document_schema" :key="index"
                class="group relative flex flex-col md:flex-row items-start md:items-center gap-6 p-6 rounded-2xl border border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-950 transition-all hover:border-indigo-500/30 shadow-sm"
            >
                <div class="flex-1 w-full space-y-1.5">
                    <span class="text-[8px] font-black uppercase text-gray-400 ml-1">Label</span>
                    <Input v-model="doc.label" @input="suggestKey(index)" placeholder="e.g. Technical Specification" class="h-11 rounded-xl" />
                </div>

                <div class="w-full md:w-64 space-y-1.5">
                    <span class="text-[8px] font-black uppercase text-gray-400 ml-1">Key</span>
                    <div class="relative">
                        <Hash class="absolute left-3 top-1/2 -translate-y-1/2 w-3 h-3 text-gray-300" />
                        <Input v-model="doc.key" placeholder="key" class="h-11 pl-8 rounded-xl font-mono bg-gray-50/50 dark:bg-gray-900 border-none text-xs" :disabled="doc.key === 'intake'" />
                    </div>
                </div>

                <div class="flex items-center gap-6 md:pt-5">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" v-model="doc.required" class="w-4 h-4 rounded border-gray-300 text-indigo-600" />
                        <span class="text-[10px] font-black uppercase text-gray-400">Required</span>
                    </label>
                    <button v-if="doc.key !== 'intake'" type="button" @click="form.document_schema.splice(index, 1)" class="p-2 text-gray-300 hover:text-red-500 transition-colors">
                        <X class="w-4 h-4" />
                    </button>
                </div>
            </div>
        </div>

        <div v-if="activeTab === 'pipeline'" class="space-y-4 animate-in fade-in duration-300">
            <div class="flex items-center justify-between mb-2">
                <p class="text-[11px] text-gray-400 font-bold uppercase tracking-widest px-1">Transition Pipeline</p>
                <Button type="button" variant="ghost" size="sm" @click="addWorkflowStep" class="h-8 text-[10px] font-black rounded-lg uppercase text-indigo-600">
                    <Plus class="w-3 h-3 mr-2" /> Add Pipeline Step
                </Button>
            </div>

            <div v-for="(step, index) in form.workflow" :key="index"
                class="relative p-8 rounded-3xl border border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-950 transition-all hover:border-indigo-500/30 shadow-sm"
            >
                <div class="flex flex-col md:flex-row items-center gap-8">
                    <div class="flex-1 w-full space-y-2">
                        <span class="text-[9px] font-black uppercase text-gray-400 ml-1">Input Source</span>
                        <select v-model="step.from_key" class="w-full h-12 rounded-xl text-xs font-bold uppercase bg-gray-50/50 dark:bg-gray-900 border-gray-200 dark:border-gray-800 px-4 focus:ring-2 focus:ring-indigo-500/10 outline-none appearance-none">
                            <option value="" disabled>Select Document</option>
                            <option v-for="s in form.document_schema" :key="s.key" :value="s.key">{{ s.label }}</option>
                        </select>
                    </div>

                    <div class="shrink-0 pt-6">
                        <div class="bg-indigo-50 dark:bg-indigo-500/10 p-2.5 rounded-full text-indigo-600">
                            <ArrowRight class="w-4 h-4" />
                        </div>
                    </div>

                    <div class="flex-1 w-full space-y-2">
                        <span class="text-[9px] font-black uppercase text-gray-400 ml-1">Output Target</span>
                        <select v-model="step.to_key" class="w-full h-12 rounded-xl text-xs font-bold uppercase bg-gray-50/50 dark:bg-gray-900 border-gray-200 dark:border-gray-800 px-4 focus:ring-2 focus:ring-indigo-500/10 outline-none appearance-none">
                            <option value="" disabled>Select Document</option>
                            <option v-for="s in form.document_schema" :key="s.key" :value="s.key">{{ s.label }}</option>
                        </select>
                    </div>
                </div>

                <div class="mt-8 pt-6 border-t border-gray-100 dark:border-gray-800">
                    <div class="flex items-center gap-2 mb-3">
                        <Sparkles class="w-3.5 h-3.5 text-indigo-500" />
                        <span class="text-[10px] font-black uppercase text-gray-500 tracking-widest">Selected AI Template</span>
                    </div>
                    <select v-model="step.ai_template_id" class="w-full h-12 rounded-xl text-xs font-bold bg-indigo-50/30 dark:bg-indigo-500/5 border-indigo-100 dark:border-indigo-500/20 px-4 text-indigo-700 dark:text-indigo-400 uppercase tracking-tight outline-none appearance-none">
                        <option :value="null">Manual Processing Only</option>
                        <option v-for="temp in aiTemplates" :key="temp.id" :value="temp.id">{{ temp.name }}</option>
                    </select>
                </div>

                <button type="button" @click="form.workflow.splice(index, 1)" class="absolute top-4 right-4 p-2 text-gray-300 hover:text-red-500 transition-colors">
                    <X class="w-4 h-4" />
                </button>
            </div>
        </div>

        <div class="flex justify-end gap-4 pt-8 border-t border-gray-100 dark:border-gray-800">
            <Button type="button" variant="ghost" @click="emit('cancel')" class="px-8 text-[10px] font-black uppercase tracking-widest text-gray-400 hover:text-gray-600 transition-colors">Cancel</Button>
            <Button type="submit" :disabled="form.processing" class="bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-black px-12 h-12 uppercase text-[10px] tracking-[0.2em] shadow-lg shadow-indigo-500/20 active:scale-95 transition-all">
                {{ editData ? 'Update Protocol' : 'Activate Protocol' }}
            </Button>
        </div>
    </form>
</template>
