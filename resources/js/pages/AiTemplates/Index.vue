<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import ResourceHeader from '@/components/ResourceHeader.vue';
import ResourceList from '@/components/ResourceList.vue';
import { type BreadcrumbItem } from '@/types';
import { Search, X, PlusIcon, Wand2, Settings2, ChevronDown, ChevronUp, Info, Sparkles } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/components/ui/dialog"
import AiTemplateForm from './Partials/AiTemplateForm.vue';

const props = defineProps<{
    templates: any[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'AI Templates', href: '/ai-templates' },
];

// --- State Management ---
const searchQuery = ref('');
const isModalOpen = ref(false);
const editingTemplate = ref<any>(null);
const collapsedGroups = ref<Record<string, boolean>>({});

const handleSuccess = () => {
    isModalOpen.value = false;
    editingTemplate.value = null;
};

const openEdit = (template: any) => {
    editingTemplate.value = template;
    isModalOpen.value = true;
};

const toggleTemplate = (id: string) => {
    collapsedGroups.value[id] = !collapsedGroups.value[id];
};

// --- Filter Logic ---
const displayItems = computed(() => {
    let list = [...props.templates];

    if (searchQuery.value.trim()) {
        const query = searchQuery.value.toLowerCase();
        list = list.filter(t => t.name.toLowerCase().includes(query));
    }

    const flattened: any[] = [];

    // Header for the entire list
    flattened.push({ isHeader: true, name: 'Active Intelligence Protocols', count: list.length });

    list.forEach((t) => {
        flattened.push({
            ...t,
            isHeader: false,
            domId: `template-${t.id}`
        });
    });

    return flattened;
});
</script>

<template>
    <Head title="AI Templates" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 w-full">

            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
                <div>
                    <h1 class="text-2xl font-black tracking-tight text-gray-900 dark:text-white">AI Intelligence Library</h1>
                    <p class="text-sm text-gray-500">Manage the prompts and logic that power project transitions.</p>
                </div>

                <Dialog v-model:open="isModalOpen">
                    <DialogTrigger asChild>
                        <Button @click="editingTemplate = null" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold h-11 px-6 rounded-xl shadow-lg shadow-indigo-500/20 active:scale-95 transition-all">
                            <PlusIcon class="w-5 h-5 mr-2" />
                            New Template
                        </Button>
                    </DialogTrigger>
                    <DialogContent class="sm:max-w-[700px] gap-0 p-0 overflow-hidden border-none rounded-3xl">
                        <div class="p-8 bg-white dark:bg-gray-950">
                            <DialogHeader class="mb-6">
                                <DialogTitle class="text-xl font-black uppercase tracking-tight">
                                    {{ editingTemplate ? 'Update Template' : 'Configure AI Logic' }}
                                </DialogTitle>
                                <DialogDescription>
                                    Define the instructions and context for this transition protocol.
                                </DialogDescription>
                            </DialogHeader>
                            <AiTemplateForm
                                :edit-data="editingTemplate"
                                @success="handleSuccess"
                            />
                        </div>
                    </DialogContent>
                </Dialog>
            </div>

            <div class="flex flex-col lg:flex-row gap-4 mb-8">
                <div class="relative flex-1 group">
                    <Search class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 group-focus-within:text-indigo-500 transition-colors" />
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Search templates..."
                        class="block w-full pl-11 pr-10 py-3 border border-gray-200 dark:border-gray-700 rounded-2xl bg-white dark:bg-gray-900 text-sm focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all shadow-sm"
                    />
                    <button v-if="searchQuery" @click="searchQuery = ''" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400">
                        <X class="w-4 h-4" />
                    </button>
                </div>
            </div>

            <div class="relative w-full">
                <div v-if="displayItems.length <= 1" class="text-center py-20 border-2 border-dashed rounded-3xl border-gray-100 dark:border-gray-800/50">
                    <p class="text-gray-400 font-medium">No AI templates found matching your criteria.</p>
                </div>

                <ResourceList :items="displayItems">
                    <template #default="{ item }">
                        <ResourceHeader
                            v-if="item.isHeader"
                            :title="item.name"
                            :count="item.count"
                            :collapsed="false"
                        />

                        <div v-else class="mb-3 w-full group">
                            <div
                                @click="toggleTemplate(item.domId)"
                                class="relative w-full p-5 bg-white dark:bg-gray-900 border border-gray-100 dark:border-gray-800 rounded-t-2xl group-hover:border-indigo-500/50 transition-all cursor-pointer shadow-sm flex items-center justify-between"
                                :class="{'rounded-b-2xl border-b': !collapsedGroups[item.domId]}"
                            >
                                <div class="flex items-center gap-4">
                                    <div class="h-12 w-12 rounded-xl bg-indigo-50 dark:bg-indigo-500/10 flex items-center justify-center text-indigo-600">
                                        <Wand2 class="w-5 h-5" />
                                    </div>
                                    <div>
                                        <h3 class="font-black uppercase tracking-tight text-gray-900 dark:text-white text-sm">{{ item.name }}</h3>
                                        <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mt-0.5">Transition Protocol</p>
                                    </div>
                                </div>

                                <div class="flex items-center gap-4">
                                    <component :is="collapsedGroups[item.domId] ? ChevronUp : ChevronDown" class="w-5 h-5 text-gray-300 group-hover:text-indigo-500" />
                                </div>
                            </div>

                            <div v-if="collapsedGroups[item.domId]" class="w-full bg-gray-50/50 dark:bg-gray-900/50 border-x border-b border-gray-100 dark:border-gray-800 rounded-b-2xl p-6 space-y-6">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div class="space-y-2">
                                        <div class="flex items-center gap-2 text-[10px] font-black uppercase tracking-widest text-gray-400">
                                            <Info class="w-3 h-3" /> System Instructions
                                        </div>
                                        <p class="text-xs text-gray-600 dark:text-gray-400 leading-relaxed bg-white dark:bg-gray-950 p-4 rounded-xl border border-gray-100 dark:border-gray-800">
                                            {{ item.system_prompt }}
                                        </p>
                                    </div>
                                    <div class="space-y-2">
                                        <div class="flex items-center gap-2 text-[10px] font-black uppercase tracking-widest text-gray-400">
                                            <Sparkles class="w-3 h-3" /> User Prompt Logic
                                        </div>
                                        <p class="text-[11px] font-mono text-gray-600 dark:text-gray-400 leading-relaxed bg-white dark:bg-gray-950 p-4 rounded-xl border border-gray-100 dark:border-gray-800">
                                            {{ item.user_prompt }}
                                        </p>
                                    </div>
                                </div>

                                <div class="flex justify-end border-t border-gray-100 dark:border-gray-800 pt-4">
                                    <Button @click.stop="openEdit(item)" variant="ghost" class="text-indigo-600 hover:text-indigo-700 font-black uppercase text-[10px] tracking-widest">
                                        <Settings2 class="w-4 h-4 mr-2" />
                                        Modify Configuration
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </template>
                </ResourceList>
            </div>
        </div>
    </AppLayout>
</template>
