<script setup lang="ts">
import { ref, computed } from 'vue';
import axios from 'axios';
import {
    ChevronRightIcon,
    ChevronDownIcon,
    TrashIcon,
    PencilIcon,
    RefreshCwIcon,
    Loader2Icon,
} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { usePage } from '@inertiajs/vue3';

const thisPage = usePage() as any;

const props = defineProps<{
    doc: any;
    isExpanded: boolean;
}>();


const emit = defineEmits<{
    (e: 'toggle'): void;
    (e: 'delete'): void;
    (e: 'edit', doc: any): void;
    (e: 'reprocessing', doc: any): void;
}>();

const isProcessing = ref(false);

/**
 * Logic to determine the attribution string
 * Shows Creator, and adds Editor only if they are a different person
 */
const attribution = computed(() => {
    // 1. Get the IDs (These are guaranteed to be there if it's in the DB)
    const cId = props.doc.creator_id;
    const eId = props.doc.editor_id;

    // 2. Logic: If the object is missing, but the ID matches the current user
    // we use the Global Auth name. This is bulletproof.
    const creatorName = props.doc.creator?.name ||
        (cId === thisPage.props.auth.user.id ? thisPage.props.auth.user.name : 'AI System');

    const editorName = props.doc.editor?.name ||
        (eId === thisPage.props.auth.user.id ? thisPage.props.auth.user.name : null);

    // 3. Only show "Edited by" if it's a different person
    if (editorName && eId !== cId) {
        return ` Created by ${creatorName} Edited by ${editorName}`;
    }

    return creatorName;
});

const handleReprocess = async () => {
    if (isProcessing.value) return;

    const actionText = props.doc.type === 'intake'
        ? 'regenerate User Stories'
        : 'generate Technical Tasks';

    if (!confirm(`Are you sure you want to ${actionText} for this document?`)) return;

    isProcessing.value = true;
    try {
        const projectId = props.doc.project_id || thisPage.props.project.id;
        await axios.post(`/projects/${projectId}/documents/${props.doc.id}/reprocess`);
        emit('reprocessing', props.doc.id);
    } catch (error) {
        console.error('AI Reprocess failed:', error);
        alert('Failed to start AI process. Check console for details.');
    } finally {
        isProcessing.value = false;
    }
};
</script>

<template>
    <li class="flex flex-col border border-slate-200 rounded-lg overflow-hidden bg-white mb-2 shadow-sm transition-all hover:border-slate-300">
        <div
            @click="emit('toggle')"
            class="flex items-center justify-between p-3 cursor-pointer hover:bg-slate-50/80 transition-colors group"
        >
            <div class="flex items-center gap-3 overflow-hidden">
                <div class="text-slate-400 group-hover:text-indigo-600 transition-colors">
                    <ChevronRightIcon v-if="!isExpanded" class="w-4 h-4" />
                    <ChevronDownIcon v-else class="w-4 h-4 text-indigo-600" />
                </div>

                <div class="flex flex-col min-w-0">
                    <span :class="['text-sm font-semibold truncate transition-colors', isExpanded ? 'text-indigo-700' : 'text-slate-800']">
                        {{ doc.name }} {{ doc.assignee_id }}
                    </span>
                    <div class="flex items-center gap-1.5 text-[10px] text-slate-400 uppercase tracking-tight font-medium">
                        <span>{{ attribution }}</span>
                        <span>•</span>
                        <span>{{ new Date(doc.updated_at || doc.created_at).toLocaleDateString() }}</span>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-1">
                <div :class="['flex items-center gap-1 transition-opacity duration-200', isExpanded ? 'opacity-100' : 'opacity-0 group-hover:opacity-100']">
                    <Button
                        v-if="['intake', 'user_story'].includes(doc.type)"
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50"
                        :disabled="isProcessing"
                        title="Reprocess with AI"
                        @click.stop="handleReprocess"
                    >
                        <Loader2Icon v-if="isProcessing" class="w-4 h-4 animate-spin text-emerald-600" />
                        <RefreshCwIcon v-else class="w-4 h-4" />
                    </Button>

                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50"
                        title="Edit Document"
                        @click.stop="emit('edit', doc)"
                    >
                        <PencilIcon class="w-4 h-4" />
                    </Button>

                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 text-slate-400 hover:text-red-600 hover:bg-red-50"
                        title="Delete Document"
                        @click.stop="emit('delete')"
                    >
                        <TrashIcon class="w-4 h-4" />
                    </Button>
                </div>
            </div>
        </div>

        <div
            v-if="isExpanded"
            class="border-t border-slate-100 bg-slate-50/50 p-4 animate-in fade-in slide-in-from-top-1 duration-200"
        >
            <div class="flex flex-col gap-4">
                <div class="prose prose-sm max-w-none">
                    <p class="text-slate-600 whitespace-pre-wrap leading-relaxed text-sm italic border-l-2 border-slate-200 pl-4">
                        {{ doc.content || 'No content provided for this document.' }}
                    </p>
                </div>

                <div v-if="doc.metadata?.criteria?.length" class="space-y-3 pt-2">
                    <h4 class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Acceptance Criteria</h4>
                    <ul class="grid gap-2">
                        <li
                            v-for="(criterion, index) in doc.metadata.criteria"
                            :key="index"
                            class="flex items-start gap-2.5 text-sm text-slate-600"
                        >
                            <div class="mt-1.5 w-1.5 h-1.5 rounded-full bg-indigo-400 shrink-0"></div>
                            <span class="leading-snug">{{ criterion }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="flex justify-end pt-4 mt-2 border-t border-slate-100/50">
                <Button
                    variant="link"
                    size="sm"
                    class="text-[11px] font-bold text-indigo-600 h-auto p-0 hover:no-underline"
                    @click.stop="emit('edit', doc)"
                >
                    <PencilIcon class="w-3 h-3 mr-1.5" />
                    Update Document Content
                </Button>
            </div>
        </div>
    </li>
</template>
