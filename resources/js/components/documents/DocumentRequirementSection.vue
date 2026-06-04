<script setup lang="ts">
import { PlusIcon } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Skeleton } from "@/components/ui/skeleton"
import DocumentItem from './DocumentItem.vue';

defineProps<{
    req: any;
    expandedDocId: string | number | null;
    isAiProcessing?: boolean;
    isTarget?: boolean;
}>();


const emit = defineEmits<{
    (e: 'openUpload', req: any): void;
    (e: 'openEdit', doc: any): void;
    (e: 'toggleExpand', id: string): void;
    (e: 'confirmDelete', doc: ProjectDocument): void;
    (e: 'reprocessing', id: string): void;
}>();

const handleMiddleReprocess = (payload: string): void => {
    emit('reprocessing', payload);
};



</script>

<template>
    <div class="border-b py-4 last:border-0">
        <div class="flex items-center justify-between mb-2">
            <div>
                <h4 class="text-[10px] font-black uppercase tracking-widest text-gray-900 dark:text-white">{{ req.plural_label }}</h4>
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">
                    {{ req.required ? 'Required' : 'Optional' }}
                </p>
            </div>
            <Button
                variant="ghost"
                size="sm"
                class="h-7 bg-projector-highlight-50 dark:bg-projector-highlight-950/30 text-projector-highlight-700 dark:text-projector-highlight-400 border border-projector-highlight-100 dark:border-projector-highlight-900/50 hover:bg-projector-highlight-100"
                @click="emit('openUpload', req)"
            >
                <PlusIcon class="w-3 h-3 mr-1" />
                Add {{ req.label }}
            </Button>
        </div>

        <ul
            v-if="req.documents.length > 0 || isTarget"
            class="mt-2 space-y-2"
        >
            <DocumentItem
                v-for="doc in req.documents"
                :key="doc.id"
                :doc="doc"
                :is-expanded="expandedDocId === doc.id"
                @toggle="emit('toggleExpand', doc.id)"
                @edit="emit('openEdit', $event)"
                @delete="emit('confirmDelete', doc)"
                @reprocessing="handleMiddleReprocess"
            />
            <li v-if="isTarget" key="skeleton" class="p-4 border border-dashed rounded-lg bg-projector-primary-50/30 border-projector-primary-200">

                <div class="flex items-center gap-2 mb-3">
                    <div class="w-1.5 h-1.5 rounded-full bg-projector-primary-500 animate-ping" />
                    <span class="text-[10px] font-bold text-projector-primary-600 uppercase tracking-widest">Drafting {{ req.label }}...</span>
                </div>
                <div class="space-y-3">
                    <Skeleton class="h-4 w-[60%] bg-projector-primary-100/50" />
                    <div class="space-y-2">
                        <Skeleton class="h-3 w-full bg-slate-100" />
                        <Skeleton class="h-3 w-[80%] bg-slate-100" />
                    </div>
                </div>
            </li>

        </ul>
        <p v-else class="text-xs text-gray-400 italic mt-1">
            No documents uploaded for this requirement.
        </p>
    </div>
</template>
