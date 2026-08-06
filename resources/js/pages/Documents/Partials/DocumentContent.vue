<script setup lang="ts">
import InlineDocumentForm from '@/components/documents/InlineDocumentForm.vue';
import { useDocumentPresenter } from '@/composables/useDocumentPresenter';
import { type InertiaForm } from '@inertiajs/vue3';
import DOMPurify from 'dompurify';
import { CheckCircle2 } from 'lucide-vue-next';

// The partial metadata interface for the "View" mode section
interface DocumentMetadata {
    criteria?: string[];
}

const props = defineProps<{
    item: ExtendedDocument;
    isEditing: boolean;
    metadata: DocumentMetadata | null;
    project: Project;
    documentTypeCatalog?: DocumentSchemaItem[];
    form: InertiaForm<DocumentFields>;
}>();

const emit = defineEmits<{
    (e: 'submit'): void;
    (e: 'cancel'): void;
    (e: 'update:isUploading', value: boolean): void;
}>();

const handleFormSubmit = () => emit('submit');
const handleCancel = () => emit('cancel');

const { getDocLabel } = useDocumentPresenter(props.documentTypeCatalog);

const sanitize = (html: string | null) => DOMPurify.sanitize(html ?? '');
</script>

<template>
    <div class="space-y-12">
        <div
            v-if="isEditing"
            class="rounded-2xl border border-slate-200 bg-slate-50 p-6"
        >
            <InlineDocumentForm
                mode="edit"
                :form="form"
                :mentionable-users="project.client?.organization?.users ?? []"
                :project-id="project.id"
                @submit="handleFormSubmit"
                @cancel="handleCancel"
                @update:is-uploading="emit('update:isUploading', $event)"
            />
        </div>

        <div v-else class="space-y-12">
            <section>
                <h1
                    class="mb-2 text-2xl font-bold text-slate-900 dark:text-slate-100"
                >
                    {{ item.name }}
                </h1>
                <div class="mb-6 flex items-center gap-3">
                    <h3
                        class="text-[11px] font-black tracking-[0.2em] text-slate-900 uppercase dark:text-slate-200"
                    >
                        {{ getDocLabel(item.type) }}
                    </h3>
                </div>
                <div
                    class="max-w-none text-[15px] leading-relaxed text-slate-900 dark:text-slate-400"
                    v-html="
                        sanitize(item.content) || 'No description provided.'
                    "
                ></div>
            </section>

            <section v-if="metadata?.criteria?.length">
                <h3
                    class="mb-6 flex items-center gap-2 text-[11px] font-black tracking-[0.2em] text-slate-700 uppercase dark:text-slate-400"
                >
                    <div class="h-px w-4 bg-slate-400 dark:bg-slate-600"></div>
                    Success Criteria
                </h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div
                        v-for="(criterion, index) in metadata.criteria"
                        :key="index"
                        class="group flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 transition-colors hover:border-emerald-200 dark:border-white/10 dark:bg-white/5 dark:hover:border-emerald-900"
                    >
                        <CheckCircle2
                            class="mt-0.5 h-4 w-4 shrink-0 text-emerald-500"
                        />
                        <span
                            class="text-[15px] leading-relaxed text-slate-900 dark:text-slate-300"
                            >{{ criterion }}</span
                        >
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
<style scoped>
/* The :deep() selector is required because the AI-generated HTML
  is injected (likely via v-html) and isn't part of the
  template during initial compilation.
*/
:deep(ol) {
    list-style-type: decimal !important;
    padding-left: 1.5rem !important;
    margin-top: 1rem;
    margin-bottom: 1rem;
}

:deep(ol li) {
    margin-bottom: 0.5rem;
}

/* Optional: Style <ul> tags while you're at it */
:deep(ul) {
    list-style-type: disc !important;
    padding-left: 1.5rem !important;
}

:deep(p) {
    margin-top: 1rem;
    margin-bottom: 1rem;
}

:deep(h1),
:deep(h2),
:deep(h3),
:deep(h4) {
    font-weight: 800;
    color: rgb(15 23 42);
    margin-top: 1.75rem;
    margin-bottom: 0.75rem;
}
:global(html.dark) :deep(h1),
:global(html.dark) :deep(h2),
:global(html.dark) :deep(h3),
:global(html.dark) :deep(h4) {
    color: rgb(241 245 249);
}

:deep(h1) {
    font-size: 1.5rem;
}
:deep(h2) {
    font-size: 1.25rem;
}
:deep(h3) {
    font-size: 1.1rem;
}
:deep(h4) {
    font-size: 1rem;
}

:deep(strong) {
    font-weight: 700;
}

:deep(table) {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1rem;
    margin-bottom: 1rem;
}

:deep(th),
:deep(td) {
    border: 1px solid rgb(226 232 240);
    padding: 0.5rem 0.75rem;
    text-align: left;
    vertical-align: top;
}

:deep(th) {
    font-weight: 700;
    background-color: rgb(248 250 252);
}

:global(html.dark) :deep(th),
:global(html.dark) :deep(td) {
    border-color: rgb(51 65 85);
}

:global(html.dark) :deep(th) {
    background-color: rgb(30 41 59);
}
</style>
