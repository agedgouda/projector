<script setup lang="ts">
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Save, Sparkles, Info, Terminal } from 'lucide-vue-next';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    editData: any | null;
}>();

const emit = defineEmits(['success']);

const form = useForm({
    name: '',
    system_prompt: '',
    user_prompt: '',
});

// Sync form with editData when opening the modal for editing
watch(() => props.editData, (newVal) => {
    if (newVal) {
        form.name = newVal.name;
        form.system_prompt = newVal.system_prompt;
        form.user_prompt = newVal.user_prompt;
    } else {
        form.reset();
    }
}, { immediate: true });

const submit = () => {
    const url = props.editData
        ? `/ai-templates/${props.editData.id}`
        : '/ai-templates';

    form[props.editData ? 'put' : 'post'](url, {
        onSuccess: () => {
            form.reset();
            emit('success');
        },
        preserveScroll: true
    });
};

// Simple visual helper for prompt engineering
const highlightVariables = (text: string) => {
    if (!text) return 'Enter prompt logic...';
    return text.replace(/\{\{input\}\}/g, '<span class="px-1.5 py-0.5 rounded bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 font-black tracking-widest text-[10px] border border-indigo-500/30">INPUT</span>');
};
</script>

<template>
    <form @submit.prevent="submit" class="space-y-8">

        <div class="space-y-2">
            <div class="flex items-center gap-2 mb-1">
                <Terminal class="w-3 h-3 text-indigo-500" />
                <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400">Template Identifier</Label>
            </div>
            <Input
                v-model="form.name"
                placeholder="e.g. Software: Note to User Story"
                class="h-12 rounded-xl border-gray-200 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-900/50 focus:ring-4 focus:ring-indigo-500/5"
            />
            <div v-if="form.errors.name" class="text-[10px] font-bold text-red-500 uppercase px-1">{{ form.errors.name }}</div>
        </div>

        <div class="space-y-2">
            <div class="flex items-center gap-2 mb-1">
                <Info class="w-3 h-3 text-amber-500" />
                <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400">System Instructions (The Persona)</Label>
            </div>
            <textarea
                v-model="form.system_prompt"
                placeholder="Describe the AI's expertise and constraints..."
                class="w-full min-h-[100px] rounded-2xl border border-gray-200 dark:border-gray-800 bg-gray-50/30 dark:bg-gray-900/30 p-4 text-sm leading-relaxed outline-none focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all font-medium text-gray-700 dark:text-gray-300"
            />
            <div v-if="form.errors.system_prompt" class="text-[10px] font-bold text-red-500 uppercase px-1">{{ form.errors.system_prompt }}</div>
        </div>

        <div class="space-y-2">
            <div class="flex items-center gap-2 mb-1">
                <Sparkles class="w-3 h-3 text-indigo-500" />
                <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400">User Prompt (The Transformation)</Label>
            </div>

            <div class="relative group">
                <textarea
                    v-model="form.user_prompt"
                    placeholder="Based on these notes: {{input}}, create a..."
                    class="w-full min-h-[180px] rounded-2xl border border-gray-200 dark:border-gray-800 bg-gray-50/30 dark:bg-gray-900/30 p-4 text-sm font-mono leading-relaxed outline-none focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all text-gray-700 dark:text-gray-300"
                />

                <div class="mt-3 p-4 bg-indigo-50/30 dark:bg-indigo-500/5 rounded-xl border border-indigo-100/50 dark:border-indigo-500/10">
                    <p class="text-[8px] font-black uppercase text-indigo-600 mb-2 tracking-widest flex items-center gap-1">
                        Injection Preview
                    </p>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 leading-relaxed" v-html="highlightVariables(form.user_prompt)"></p>
                </div>
            </div>
            <div v-if="form.errors.user_prompt" class="text-[10px] font-bold text-red-500 uppercase px-1">{{ form.errors.user_prompt }}</div>
        </div>

        <div class="flex justify-end gap-3 pt-6 border-t border-gray-100 dark:border-gray-800">
            <Button
                type="submit"
                :disabled="form.processing"
                class="w-full bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-black px-10 h-12 shadow-lg shadow-indigo-500/20 transition-all active:scale-95 uppercase text-[10px] tracking-[0.2em]"
            >
                <Save class="w-4 h-4 mr-2" />
                {{ editData ? 'Update AI Logic' : 'Activate Intelligence' }}
            </Button>
        </div>
    </form>
</template>
