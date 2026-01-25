<script setup lang="ts">
import { CheckCircle2 } from 'lucide-vue-next';
import InlineDocumentForm from '@/components/documents/InlineDocumentForm.vue';
import { type InertiaForm } from '@inertiajs/vue3';
import { useDocumentPresenter } from '@/composables/useDocumentPresenter';

// The partial metadata interface for the "View" mode section
interface DocumentMetadata {
    criteria?: string[];
}

const props = defineProps<{
    item: ExtendedDocument;
    isEditing: boolean;
    metadata: DocumentMetadata | null;
    project: Project;
    form: InertiaForm<DocumentFields>;
}>();

const emit = defineEmits<{
    (e: 'submit'): void;
    (e: 'cancel'): void;
}>();

const handleFormSubmit = () => emit('submit');
const handleCancel = () => emit('cancel');

const { getDocLabel } = useDocumentPresenter(props.project);
</script>

<template>
    <div class="space-y-12">
        <div v-if="isEditing" class="bg-slate-50 rounded-2xl p-6 border border-slate-200">
            <InlineDocumentForm
                mode="edit"
                :form="form"
                @submit="handleFormSubmit"
                @cancel="handleCancel"
            />
        </div>

        <div v-else class="space-y-12">
            <section>
                <div class="flex items-center gap-3 mb-6">
                    <h3 class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-800">
                        {{ getDocLabel(item.type) }}
                    </h3>
                </div>
                <div
                    class="text-[15px] text-slate-600 leading-relaxed prose prose-slate max-w-none"
                    v-html="item.content || 'No description provided.'"
                ></div>
            </section>

            <section v-if="metadata?.criteria?.length">
                <h3 class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 mb-6 flex items-center gap-2">
                    <div class="w-4 h-px bg-slate-200"></div> Success Criteria
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div
                        v-for="(criterion, index) in metadata.criteria"
                        :key="index"
                        class="flex items-start gap-3 p-4 rounded-xl border border-slate-100 bg-slate-50/30 group hover:border-emerald-100 transition-colors"
                    >
                        <CheckCircle2 class="h-4 w-4 text-emerald-500 mt-0.5 shrink-0" />
                        <span class="text-[13px] text-slate-600 leading-snug">{{ criterion }}</span>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
