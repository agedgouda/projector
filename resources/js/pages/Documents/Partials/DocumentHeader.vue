<script setup lang="ts">
import { ArrowLeft, Edit2, Trash2, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

defineProps<{
    project: Project;
    item: {
        id?: string | number;
        name: string;
    };
    isEditing: boolean;
}>();

const emit = defineEmits(['back', 'toggle-edit', 'delete']);
</script>

<template>
    <nav class="border-b bg-slate-50/50 px-8 py-4">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-4 text-sm text-slate-500">
                <button
                    @click="emit('back')"
                    class="hover:text-indigo-600 transition-colors flex items-center gap-2 cursor-pointer bg-transparent border-0 p-0"
                >
                    <ArrowLeft class="w-3 h-3" />
                    {{ project.name }}
                </button>
                <span class="text-slate-300">/</span>
                <span class="font-medium text-slate-900 truncate max-w-[300px]">{{ item.name }}</span>
            </div>

            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    @click="emit('toggle-edit')"
                    class="relative h-8 w-24 text-[10px] font-black uppercase tracking-widest flex items-center justify-center transition-all duration-200"
                >
                    <div class="absolute left-3 flex items-center justify-center">
                        <component
                            :is="isEditing ? X : Edit2"
                            class="h-3 w-3 transition-transform duration-200"
                            :class="{ 'rotate-90': isEditing }"
                        />
                    </div>
                    <span class="ml-4">{{ isEditing ? 'Cancel' : 'Edit' }}</span>
                </Button>

                <Button
                    variant="ghost"
                    size="icon"
                    @click="emit('delete')"
                    class="h-8 w-8 p-0 text-slate-400 hover:text-red-600 hover:bg-red-50 flex items-center justify-center"
                >
                    <Trash2 class="h-4 w-4" />
                </Button>
            </div>
        </div>
    </nav>
</template>
