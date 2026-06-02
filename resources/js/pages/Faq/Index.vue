<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, usePage, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem, type AppPageProps } from '@/types';
import { Search, Plus, Edit2, Trash2, X, Check, ChevronDown, ChevronUp } from 'lucide-vue-next';
import faqRoutes from '@/routes/faq/index';

interface FaqItem {
    id: number;
    category: string;
    question: string;
    answer: string;
    keywords: string | null;
    order: number;
}

const props = defineProps<{
    faqs: FaqItem[];
}>();

const page = usePage<AppPageProps>();
const isSuperAdmin = computed(() => page.props.auth.user?.roles?.includes('super-admin') ?? false);

const breadcrumbs: BreadcrumbItem[] = [{ title: 'FAQ', href: '/faq' }];

const searchQuery = ref('');
const expandedIds = ref<Set<number>>(new Set());
const editingId = ref<number | null>(null);
const showAddForm = ref(false);

const filtered = computed(() => {
    const q = searchQuery.value.toLowerCase().trim();
    if (!q) return props.faqs;
    return props.faqs.filter(f =>
        f.question.toLowerCase().includes(q) ||
        f.answer.toLowerCase().includes(q) ||
        f.category.toLowerCase().includes(q) ||
        (f.keywords ?? '').toLowerCase().includes(q)
    );
});

const grouped = computed(() => {
    const map = new Map<string, FaqItem[]>();
    for (const faq of filtered.value) {
        if (!map.has(faq.category)) map.set(faq.category, []);
        map.get(faq.category)!.push(faq);
    }
    return map;
});

const toggle = (id: number) => {
    if (expandedIds.value.has(id)) {
        expandedIds.value.delete(id);
    } else {
        expandedIds.value.add(id);
    }
};

// Edit form
const editForm = useForm({ category: '', question: '', answer: '', keywords: '', order: 0 });

const startEdit = (faq: FaqItem) => {
    editingId.value = faq.id;
    editForm.category = faq.category;
    editForm.question = faq.question;
    editForm.answer = faq.answer;
    editForm.keywords = faq.keywords ?? '';
    editForm.order = faq.order;
    expandedIds.value.add(faq.id);
};

const cancelEdit = () => {
    editingId.value = null;
    editForm.reset();
};

const saveEdit = (faq: FaqItem) => {
    editForm.put(faqRoutes.update(faq.id).url, {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null; },
    });
};

const deleteFaq = (faq: FaqItem) => {
    if (!confirm(`Delete "${faq.question}"?`)) return;
    router.delete(faqRoutes.destroy(faq.id).url, { preserveScroll: true });
};

// Add form
const addForm = useForm({ category: '', question: '', answer: '', keywords: '', order: 0 });

const submitAdd = () => {
    addForm.post(faqRoutes.store().url, {
        preserveScroll: true,
        onSuccess: () => {
            addForm.reset();
            showAddForm.value = false;
        },
    });
};
</script>

<template>
    <Head title="FAQ" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 w-full space-y-8">

            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-black tracking-tighter text-gray-900 dark:text-white uppercase">
                        Frequently Asked Questions
                    </h1>
                    <p class="text-sm text-gray-500 mt-1">Find answers to common questions about Projector.</p>
                </div>
                <div class="flex items-center gap-3 w-full sm:w-auto">
                    <div class="relative w-full sm:w-72">
                        <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400 pointer-events-none" />
                        <Input v-model="searchQuery" placeholder="Search FAQs..." class="pl-9 h-10" />
                    </div>
                    <Button
                        v-if="isSuperAdmin"
                        @click="showAddForm = !showAddForm"
                        class="bg-projector-primary-600 hover:bg-projector-primary-700 text-white h-10 px-4 font-black text-[10px] uppercase tracking-widest rounded-xl whitespace-nowrap"
                    >
                        <Plus class="w-4 h-4 mr-1.5" /> Add FAQ
                    </Button>
                </div>
            </div>

            <!-- Add Form -->
            <div v-if="showAddForm && isSuperAdmin" class="bg-white dark:bg-slate-900 border border-projector-primary-200 dark:border-projector-primary-800 rounded-2xl p-6 space-y-4">
                <h3 class="text-[10px] font-black uppercase tracking-widest text-projector-primary-600">New FAQ Item</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Category</label>
                        <Input v-model="addForm.category" placeholder="e.g. General & Onboarding" />
                    </div>
                    <div class="space-y-1">
                        <label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Order</label>
                        <Input v-model.number="addForm.order" type="number" min="0" />
                    </div>
                </div>
                <div class="space-y-1">
                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Question</label>
                    <Input v-model="addForm.question" placeholder="Question..." />
                </div>
                <div class="space-y-1">
                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Answer</label>
                    <textarea
                        v-model="addForm.answer"
                        rows="3"
                        placeholder="Answer..."
                        class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-slate-950 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-projector-primary-500/20 outline-none resize-none"
                    />
                </div>
                <div class="space-y-1">
                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Keywords (comma separated)</label>
                    <Input v-model="addForm.keywords" placeholder="e.g. intro, overview, ai" />
                </div>
                <div class="flex justify-end gap-2">
                    <Button variant="ghost" @click="showAddForm = false; addForm.reset()" class="text-[10px] font-black uppercase">Cancel</Button>
                    <Button @click="submitAdd" :disabled="addForm.processing" class="bg-projector-primary-600 hover:bg-projector-primary-700 text-white text-[10px] font-black uppercase tracking-widest">
                        Save
                    </Button>
                </div>
            </div>

            <!-- Empty state -->
            <div v-if="grouped.size === 0" class="py-24 text-center border-2 border-dashed border-gray-100 dark:border-gray-800 rounded-3xl">
                <Search class="w-10 h-10 mx-auto mb-4 text-gray-200" />
                <h3 class="font-black text-gray-400 uppercase tracking-widest text-xs">No results found</h3>
            </div>

            <!-- FAQ Groups -->
            <div v-for="[category, items] in grouped" :key="category" class="space-y-2">
                <h2 class="text-[10px] font-black uppercase tracking-[0.2em] text-projector-primary-600 dark:text-projector-primary-400 px-1 flex items-center gap-2">
                    <div class="w-4 h-px bg-projector-primary-300 dark:bg-projector-primary-700"></div>
                    {{ category }}
                </h2>

                <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl overflow-hidden shadow-sm">
                    <div v-for="(faq, idx) in items" :key="faq.id">

                        <!-- Edit mode -->
                        <div v-if="editingId === faq.id" class="p-5 space-y-4 border-b border-slate-100 dark:border-slate-800 last:border-0 bg-projector-primary-50/40 dark:bg-projector-primary-900/10">
                            <div class="grid grid-cols-2 gap-4">
                                <div class="space-y-1">
                                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Category</label>
                                    <Input v-model="editForm.category" />
                                </div>
                                <div class="space-y-1">
                                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Order</label>
                                    <Input v-model.number="editForm.order" type="number" min="0" />
                                </div>
                            </div>
                            <div class="space-y-1">
                                <label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Question</label>
                                <Input v-model="editForm.question" />
                            </div>
                            <div class="space-y-1">
                                <label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Answer</label>
                                <textarea
                                    v-model="editForm.answer"
                                    rows="3"
                                    class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-slate-950 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-projector-primary-500/20 outline-none resize-none"
                                />
                            </div>
                            <div class="space-y-1">
                                <label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Keywords</label>
                                <Input v-model="editForm.keywords" />
                            </div>
                            <div class="flex justify-end gap-2">
                                <Button variant="ghost" size="sm" @click="cancelEdit" class="h-8 text-[10px] font-black uppercase">
                                    <X class="w-3 h-3 mr-1" /> Cancel
                                </Button>
                                <Button size="sm" @click="saveEdit(faq)" :disabled="editForm.processing" class="h-8 bg-projector-primary-600 hover:bg-projector-primary-700 text-white text-[10px] font-black uppercase tracking-widest">
                                    <Check class="w-3 h-3 mr-1" /> Save
                                </Button>
                            </div>
                        </div>

                        <!-- View mode -->
                        <div v-else :class="['border-b border-slate-100 dark:border-slate-800 last:border-0', idx % 2 === 1 ? 'bg-slate-50/50 dark:bg-slate-800/20' : '']">
                            <button
                                type="button"
                                class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors"
                                @click="toggle(faq.id)"
                            >
                                <span class="font-semibold text-sm text-slate-800 dark:text-slate-100 pr-4">{{ faq.question }}</span>
                                <div class="flex items-center gap-2 shrink-0">
                                    <template v-if="isSuperAdmin">
                                        <button
                                            type="button"
                                            @click.stop="startEdit(faq)"
                                            class="p-1.5 rounded-lg text-slate-400 hover:text-projector-primary-600 hover:bg-projector-primary-50 dark:hover:bg-projector-primary-900/20 transition-colors"
                                        >
                                            <Edit2 class="w-3.5 h-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            @click.stop="deleteFaq(faq)"
                                            class="p-1.5 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                                        >
                                            <Trash2 class="w-3.5 h-3.5" />
                                        </button>
                                    </template>
                                    <ChevronDown v-if="!expandedIds.has(faq.id)" class="w-4 h-4 text-slate-400" />
                                    <ChevronUp v-else class="w-4 h-4 text-projector-primary-500" />
                                </div>
                            </button>
                            <div v-if="expandedIds.has(faq.id)" class="px-5 pb-5">
                                <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">{{ faq.answer }}</p>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
