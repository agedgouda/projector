<script setup lang="ts">
import { ref, computed } from 'vue';
import { PlusIcon, Sparkles, Search } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import DocumentRequirementSection from './DocumentRequirementSection.vue';

const props = defineProps<{
    requirementStatus: any[];
    canGenerate: boolean;
    isGenerating: boolean;
}>();

const emit = defineEmits(['openUpload', 'confirmDelete', 'generate']);

const searchQuery = ref('');
const expandedDocId = ref<string | number | null>(null);
const selectedTypes = ref<string[]>(props.requirementStatus.map(r => r.key));

const toggleType = (key: string) => {
    const index = selectedTypes.value.indexOf(key);
    if (index > -1) {
        selectedTypes.value.splice(index, 1);
    } else {
        selectedTypes.value.push(key);
    }
};

const filteredRequirements = computed(() => {
    const query = searchQuery.value.toLowerCase();
    return props.requirementStatus
        .filter(req => selectedTypes.value.includes(req.key))
        .map(req => ({
            ...req,
            documents: req.documents.filter((doc: any) =>
                doc.name.toLowerCase().includes(query) ||
                (doc.content && doc.content.toLowerCase().includes(query))
            )
        }))
        .filter(req => req.documents.length > 0 || (query === '' && selectedTypes.value.includes(req.key)));
});

const toggleExpand = (id: string | number) => {
    expandedDocId.value = expandedDocId.value === id ? null : id;
};
</script>

<template>
    <div class="bg-white p-6 rounded-xl mt-8 border border-gray-200 shadow-sm">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-bold text-gray-900">Project Documents</h3>
            <Button @click="emit('openUpload')" size="sm" class="gap-2 bg-gray-900 text-white shadow-sm">
                <PlusIcon class="w-4 h-4" /> Add Document
            </Button>
        </div>

        <div class="bg-slate-50/80 p-3 rounded-lg border border-slate-200/60 mb-6 space-y-3">
            <div class="flex flex-wrap gap-1.5">
                <button
                    v-for="req in requirementStatus"
                    :key="req.key"
                    @click="toggleType(req.key)"
                    :class="[
                        'px-2.5 py-1 rounded-md border text-[10px] font-bold uppercase tracking-tight transition-all flex items-center gap-2',
                        selectedTypes.includes(req.key)
                            ? 'bg-white border-slate-300 text-slate-900 shadow-sm'
                            : 'bg-transparent border-transparent text-slate-400 hover:text-slate-600'
                    ]"
                >
                    <div
                        :class="[
                            'w-1.5 h-1.5 rounded-full',
                            selectedTypes.includes(req.key) ? 'bg-indigo-500' : 'bg-slate-300'
                        ]"
                    ></div>
                    {{ req.label }}s
                </button>
            </div>

            <div class="relative">
                <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400" />
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search documents..."
                    class="w-full pl-9 pr-4 py-2 bg-white border border-slate-200 rounded-md text-sm outline-none focus:ring-1 focus:ring-indigo-500 transition-all placeholder:text-slate-400 shadow-sm"
                />
            </div>
        </div>

        <div class="space-y-1 min-h-[100px]">
            <DocumentRequirementSection
                v-for="req in filteredRequirements"
                :key="req.key"
                :req="req"
                :expanded-doc-id="expandedDocId"
                @open-upload="(r) => emit('openUpload', r)"
                @toggle-expand="toggleExpand"
                @confirm-delete="(doc) => emit('confirmDelete', doc)"
            />

            <div v-if="filteredRequirements.length === 0" class="py-12 text-center border-2 border-dashed border-slate-100 rounded-xl bg-slate-50/30">
                <p class="text-sm text-slate-400 italic">No documents match your selection.</p>
                <button
                    @click="selectedTypes = requirementStatus.map(r => r.key); searchQuery = ''"
                    class="mt-2 text-xs text-indigo-600 font-semibold hover:underline"
                >
                    Reset all filters
                </button>
            </div>
        </div>

        <div class="mt-6 pt-6 border-t border-gray-100">
            <Button
                @click="emit('generate')"
                class="w-full py-6 font-bold uppercase tracking-widest text-xs shadow-sm bg-indigo-600 hover:bg-indigo-700"
                :disabled="!canGenerate || isGenerating"
            >
                <Sparkles v-if="!isGenerating" class="w-4 h-4 mr-2" />
                {{ isGenerating ? 'Processing AI...' : (canGenerate ? 'Generate Deliverables' : 'Upload Required Specs to Start') }}
            </Button>
        </div>
    </div>
</template>
