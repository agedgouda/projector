<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { PlusIcon } from 'lucide-vue-next';
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

const handleMiddleReprocess = (payload: string | number): void => {
    emit('reprocessing', String(payload));
};
</script>

<template>
    <div class="border-b py-4 last:border-0">
        <div class="mb-2 flex items-center justify-between">
            <div>
                <h4
                    class="text-[10px] font-black tracking-widest text-gray-900 uppercase dark:text-white"
                >
                    {{ req.plural_label }}
                </h4>
                <p
                    class="text-[10px] font-black tracking-widest text-gray-400 uppercase"
                >
                    {{ req.required ? 'Required' : 'Optional' }}
                </p>
            </div>
            <Button
                variant="ghost"
                size="sm"
                class="h-7 border border-projector-highlight-100 bg-projector-highlight-50 text-projector-highlight-700 hover:bg-projector-highlight-100 dark:border-projector-highlight-900/50 dark:bg-projector-highlight-950/30 dark:text-projector-highlight-400"
                @click="emit('openUpload', req)"
            >
                <PlusIcon class="mr-1 h-3 w-3" />
                Add {{ req.label }}
            </Button>
        </div>

        <ul v-if="req.documents.length > 0 || isTarget" class="mt-2 space-y-2">
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
            <li
                v-if="isTarget"
                key="skeleton"
                class="rounded-lg border border-dashed border-projector-primary-200 bg-projector-primary-50/30 p-4"
            >
                <div class="mb-3 flex items-center gap-2">
                    <div
                        class="h-1.5 w-1.5 animate-ping rounded-full bg-projector-primary-500"
                    />
                    <span
                        class="text-[10px] font-bold tracking-widest text-projector-primary-600 uppercase"
                        >Drafting {{ req.label }}...</span
                    >
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
        <p v-else class="mt-1 text-xs text-gray-400 italic">
            No documents uploaded for this requirement.
        </p>
    </div>
</template>
