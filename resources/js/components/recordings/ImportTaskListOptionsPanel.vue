<script setup lang="ts">
import { FileSpreadsheet } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';

// Placement/layout only for now — deliberately not wired to anything yet (no emits, no file
// inputs). Kept as its own panel (rather than folded into ImportDocumentOptionsPanel.vue)
// since a task-list import skips the transcript/AI pipeline entirely — it creates tasks
// directly from structured rows, not a document for the AI to interpret — so it reads as a
// separate concern with its own heading, not another row in "Import a Transcript."
defineProps<{
    canManage: boolean;
}>();
</script>

<template>
    <section v-if="canManage" class="mb-8">
        <h2 class="mb-4 text-[10px] font-black tracking-widest text-gray-400 uppercase">
            Import a Task List
        </h2>

        <div class="grid gap-0.5">
            <div :class="['flex items-center gap-3 h-12 px-2 rounded-md transition-colors', FLAT_ROW_HOVER]">
                <div class="flex h-4 w-4 shrink-0 items-center justify-center text-slate-400">
                    <FileSpreadsheet class="h-3.5 w-3.5" />
                </div>
                <div class="min-w-0 flex-1">
                    <span class="text-[13px] font-semibold text-slate-900 dark:text-slate-100">Upload CSV (.csv)</span>
                </div>
                <Button
                    size="sm"
                    variant="outline"
                    class="shrink-0 rounded-md px-3 h-8 text-[10px] font-black uppercase tracking-widest text-slate-600 dark:text-slate-300"
                >
                    Choose File
                </Button>
            </div>

            <div :class="['flex items-center gap-3 h-12 px-2 rounded-md transition-colors', FLAT_ROW_HOVER]">
                <div class="flex h-4 w-4 shrink-0 items-center justify-center text-slate-400">
                    <FileSpreadsheet class="h-3.5 w-3.5" />
                </div>
                <div class="min-w-0 flex-1">
                    <span class="text-[13px] font-semibold text-slate-900 dark:text-slate-100">Upload Excel (.xlsx)</span>
                </div>
                <Button
                    size="sm"
                    variant="outline"
                    class="shrink-0 rounded-md px-3 h-8 text-[10px] font-black uppercase tracking-widest text-slate-600 dark:text-slate-300"
                >
                    Choose File
                </Button>
            </div>
        </div>
    </section>
</template>
