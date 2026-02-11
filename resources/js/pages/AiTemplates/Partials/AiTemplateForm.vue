<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

/* ---------------------------
   1. Props & Emits
   Instead of a single 'form' prop, we define the individual
   model values to allow clean parent-child synchronization.
---------------------------- */
defineProps<{
    name: string;
    systemPrompt: string;
    userPrompt: string;
    processing: boolean;
    errors: Record<string, string>;
    isEditing: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:name', value: string): void;
    (e: 'update:systemPrompt', value: string): void;
    (e: 'update:userPrompt', value: string): void;
    (e: 'submit'): void;
    (e: 'cancel'): void;
}>();


</script>

<template>
    <form @submit.prevent="emit('submit')" class="space-y-8">
        <div class="space-y-2">
            <div class="flex items-center gap-2 mb-1">
                <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400">Name</Label>
            </div>
            <Input
                :model-value="name"
                @update:model-value="val => emit('update:name', val as string)"
                placeholder="e.g. Software: Note to User Story"
                class="h-12 rounded-xl border-gray-200 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-900/50 focus:ring-4 focus:ring-indigo-500/5"
            />
            <div v-if="errors.name" class="text-[10px] font-bold text-red-500 uppercase px-1">{{ errors.name }}</div>
        </div>

        <div class="space-y-2">
            <div class="flex items-center gap-2 mb-1">
                <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400">System Instructions (The Persona)</Label>
            </div>
            <textarea
                :value="systemPrompt"
                @input="e => emit('update:systemPrompt', (e.target as HTMLTextAreaElement).value)"
                placeholder="Describe the AI's expertise and constraints..."
                class="w-full min-h-[100px] rounded-2xl border border-gray-200 dark:border-gray-800 bg-gray-50/30 dark:bg-gray-900/30 p-4 text-sm leading-relaxed outline-none focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all font-medium text-gray-700 dark:text-gray-300"
            />
            <div v-if="errors.system_prompt" class="text-[10px] font-bold text-red-500 uppercase px-1">{{ errors.system_prompt }}</div>
        </div>

        <div class="space-y-2">
            <div class="flex items-center gap-2 mb-1">
                <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400">User Prompt (The Transformation)</Label>
            </div>

            <div class="relative group">
                <textarea
                    :value="userPrompt"
                    @input="e => emit('update:userPrompt', (e.target as HTMLTextAreaElement).value)"
                    placeholder="Based on these notes: {{input}}, create a..."
                    class="w-full min-h-[180px] rounded-2xl border border-gray-200 dark:border-gray-800 bg-gray-50/30 dark:bg-gray-900/30 p-4 text-sm font-mono leading-relaxed outline-none focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all text-gray-700 dark:text-gray-300"
                />
            </div>
            <div v-if="errors.user_prompt" class="text-[10px] font-bold text-red-500 uppercase px-1">{{ errors.user_prompt }}</div>
        </div>

        <div class="flex items-center justify-end gap-3 pt-6 border-t border-gray-100 dark:border-gray-800">
            <Button type="button" variant="ghost" @click="emit('cancel')" class="text-[10px] font-black uppercase tracking-widest text-gray-400">
                Cancel
            </Button>
            <Button
                type="submit"
                :disabled="processing"
                class="bg-indigo-600 hover:bg-indigo-700 text-white font-black uppercase text-[10px] tracking-widest px-8 h-12 rounded-xl shadow-lg"
            >
                {{ isEditing ? 'Save Changes' : 'Activate Intelligence' }}
            </Button>
        </div>
    </form>
</template>
