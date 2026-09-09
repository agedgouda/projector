<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { FLAT_ACTION_BUTTON, FLAT_ROW_HOVER } from '@/lib/flat-ui';
import faqRoutes from '@/routes/faq/index';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Check,
    ChevronDown,
    Edit2,
    Plus,
    Search,
    Trash2,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

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
const isSuperAdmin = computed(
    () => page.props.auth.user?.roles?.includes('super-admin') ?? false,
);

const breadcrumbs: BreadcrumbItem[] = [{ title: 'FAQ', href: '/faq' }];

const searchQuery = ref('');
const expandedIds = ref<Set<number>>(new Set());
const editingId = ref<number | null>(null);
const showAddForm = ref(false);

const filtered = computed(() => {
    const q = searchQuery.value.toLowerCase().trim();
    if (!q) return props.faqs;
    return props.faqs.filter(
        (f) =>
            f.question.toLowerCase().includes(q) ||
            f.answer.toLowerCase().includes(q) ||
            f.category.toLowerCase().includes(q) ||
            (f.keywords ?? '').toLowerCase().includes(q),
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
const editForm = useForm({
    category: '',
    question: '',
    answer: '',
    keywords: '',
    order: 0,
});

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
        onSuccess: () => {
            editingId.value = null;
        },
    });
};

const deleteFaq = (faq: FaqItem) => {
    if (!confirm(`Delete "${faq.question}"?`)) return;
    router.delete(faqRoutes.destroy(faq.id).url, { preserveScroll: true });
};

// Add form
const addForm = useForm({
    category: '',
    question: '',
    answer: '',
    keywords: '',
    order: 0,
});

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
        <div class="w-full space-y-8 p-6">
            <div
                class="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center"
            >
                <div>
                    <h1
                        class="text-2xl font-black tracking-tighter text-gray-900 uppercase dark:text-white"
                    >
                        Frequently Asked Questions
                    </h1>
                    <p class="mt-1 text-sm text-gray-500">
                        Find answers to common questions about Projector.
                    </p>
                </div>
                <div class="flex w-full items-center gap-3 sm:w-auto">
                    <div class="relative w-full sm:w-72">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400"
                        />
                        <Input
                            v-model="searchQuery"
                            placeholder="Search FAQs..."
                            class="h-10 pl-9"
                        />
                    </div>
                    <Button
                        v-if="isSuperAdmin"
                        @click="showAddForm = !showAddForm"
                        class="h-10 rounded-xl bg-projector-primary-600 px-4 text-[10px] font-black tracking-widest whitespace-nowrap text-white uppercase hover:bg-projector-primary-700"
                    >
                        <Plus class="mr-1.5 h-4 w-4" /> Add FAQ
                    </Button>
                </div>
            </div>

            <!-- Add Form -->
            <div
                v-if="showAddForm && isSuperAdmin"
                class="space-y-4 rounded-2xl border border-projector-primary-200 bg-white p-6 dark:border-projector-primary-800 dark:bg-slate-900"
            >
                <h3
                    class="text-[10px] font-black tracking-widest text-projector-primary-600 uppercase"
                >
                    New FAQ Item
                </h3>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <label
                            class="text-[10px] font-black tracking-widest text-slate-400 uppercase"
                            >Category</label
                        >
                        <Input
                            v-model="addForm.category"
                            placeholder="e.g. General & Onboarding"
                        />
                    </div>
                    <div class="space-y-1">
                        <label
                            class="text-[10px] font-black tracking-widest text-slate-400 uppercase"
                            >Order</label
                        >
                        <Input
                            v-model.number="addForm.order"
                            type="number"
                            min="0"
                        />
                    </div>
                </div>
                <div class="space-y-1">
                    <label
                        class="text-[10px] font-black tracking-widest text-slate-400 uppercase"
                        >Question</label
                    >
                    <Input
                        v-model="addForm.question"
                        placeholder="Question..."
                    />
                </div>
                <div class="space-y-1">
                    <label
                        class="text-[10px] font-black tracking-widest text-slate-400 uppercase"
                        >Answer</label
                    >
                    <textarea
                        v-model="addForm.answer"
                        rows="3"
                        placeholder="Answer..."
                        class="w-full resize-none rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:ring-2 focus:ring-projector-primary-500/20 dark:border-gray-700 dark:bg-slate-950 dark:text-gray-100"
                    />
                </div>
                <div class="space-y-1">
                    <label
                        class="text-[10px] font-black tracking-widest text-slate-400 uppercase"
                        >Keywords (comma separated)</label
                    >
                    <Input
                        v-model="addForm.keywords"
                        placeholder="e.g. intro, overview, ai"
                    />
                </div>
                <div class="flex justify-end gap-2">
                    <Button
                        @click="
                            showAddForm = false;
                            addForm.reset();
                        "
                        class="border border-projector-primary-600 bg-white text-[10px] font-black text-projector-primary-600 uppercase hover:bg-projector-primary-50 dark:border-projector-primary-400 dark:bg-transparent dark:text-projector-primary-400 dark:hover:bg-projector-primary-950/30"
                        >Cancel</Button
                    >
                    <Button
                        @click="submitAdd"
                        :disabled="addForm.processing"
                        class="bg-projector-primary-600 text-[10px] font-black tracking-widest text-white uppercase hover:bg-projector-primary-700"
                    >
                        Save
                    </Button>
                </div>
            </div>

            <!-- Empty state -->
            <div
                v-if="grouped.size === 0"
                class="rounded-3xl border-2 border-dashed border-gray-100 py-24 text-center dark:border-gray-800"
            >
                <Search class="mx-auto mb-4 h-10 w-10 text-gray-200" />
                <h3
                    class="text-xs font-black tracking-widest text-gray-400 uppercase"
                >
                    No results found
                </h3>
            </div>

            <!-- FAQ Groups -->
            <div
                v-for="[category, items] in grouped"
                :key="category"
                class="space-y-1"
            >
                <h2
                    class="flex items-center gap-2 px-2 text-[10px] font-black tracking-[0.2em] text-projector-primary-600 uppercase dark:text-projector-primary-400"
                >
                    <div
                        class="h-px w-4 bg-projector-primary-300 dark:bg-projector-primary-700"
                    ></div>
                    {{ category }}
                </h2>

                <div class="grid gap-0.5">
                    <div v-for="faq in items" :key="faq.id">
                        <!-- Edit mode -->
                        <div
                            v-if="editingId === faq.id"
                            class="space-y-4 rounded-md bg-projector-primary-50/40 p-5 dark:bg-projector-primary-900/10"
                        >
                            <div class="grid grid-cols-2 gap-4">
                                <div class="space-y-1">
                                    <label
                                        class="text-[10px] font-black tracking-widest text-slate-400 uppercase"
                                        >Category</label
                                    >
                                    <Input v-model="editForm.category" />
                                </div>
                                <div class="space-y-1">
                                    <label
                                        class="text-[10px] font-black tracking-widest text-slate-400 uppercase"
                                        >Order</label
                                    >
                                    <Input
                                        v-model.number="editForm.order"
                                        type="number"
                                        min="0"
                                    />
                                </div>
                            </div>
                            <div class="space-y-1">
                                <label
                                    class="text-[10px] font-black tracking-widest text-slate-400 uppercase"
                                    >Question</label
                                >
                                <Input v-model="editForm.question" />
                            </div>
                            <div class="space-y-1">
                                <label
                                    class="text-[10px] font-black tracking-widest text-slate-400 uppercase"
                                    >Answer</label
                                >
                                <textarea
                                    v-model="editForm.answer"
                                    rows="3"
                                    class="w-full resize-none rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:ring-2 focus:ring-projector-primary-500/20 dark:border-gray-700 dark:bg-slate-950 dark:text-gray-100"
                                />
                            </div>
                            <div class="space-y-1">
                                <label
                                    class="text-[10px] font-black tracking-widest text-slate-400 uppercase"
                                    >Keywords</label
                                >
                                <Input v-model="editForm.keywords" />
                            </div>
                            <div class="flex justify-end gap-2">
                                <Button
                                    size="sm"
                                    @click="cancelEdit"
                                    class="h-8 border border-projector-primary-600 bg-white text-[10px] font-black text-projector-primary-600 uppercase hover:bg-projector-primary-50 dark:border-projector-primary-400 dark:bg-transparent dark:text-projector-primary-400 dark:hover:bg-projector-primary-950/30"
                                >
                                    <X class="mr-1 h-3 w-3" /> Cancel
                                </Button>
                                <Button
                                    size="sm"
                                    @click="saveEdit(faq)"
                                    :disabled="editForm.processing"
                                    class="h-8 bg-projector-primary-600 text-[10px] font-black tracking-widest text-white uppercase hover:bg-projector-primary-700"
                                >
                                    <Check class="mr-1 h-3 w-3" /> Save
                                </Button>
                            </div>
                        </div>

                        <!-- View mode -->
                        <div v-else class="group">
                            <button
                                type="button"
                                :class="[
                                    'flex h-12 w-full items-center justify-between gap-3 rounded-md px-2 text-left transition-colors',
                                    FLAT_ROW_HOVER,
                                ]"
                                @click="toggle(faq.id)"
                            >
                                <span
                                    class="truncate pr-4 text-[13px] font-semibold text-slate-900 dark:text-slate-100"
                                    >{{ faq.question }}</span
                                >
                                <div class="flex shrink-0 items-center gap-1">
                                    <template v-if="isSuperAdmin">
                                        <button
                                            type="button"
                                            @click.stop="startEdit(faq)"
                                            :class="FLAT_ACTION_BUTTON"
                                        >
                                            <Edit2 class="h-3.5 w-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            @click.stop="deleteFaq(faq)"
                                            class="flex h-7 w-7 items-center justify-center rounded-md text-slate-400 opacity-0 transition-colors group-hover:opacity-100 hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-950/30"
                                        >
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </button>
                                    </template>
                                    <ChevronDown
                                        class="h-4 w-4 text-slate-400 transition-transform duration-200"
                                        :class="{
                                            'rotate-180 text-projector-primary-500':
                                                expandedIds.has(faq.id),
                                        }"
                                    />
                                </div>
                            </button>
                            <div
                                v-if="expandedIds.has(faq.id)"
                                class="px-2 pb-4"
                            >
                                <p
                                    class="text-[13px] leading-relaxed whitespace-pre-wrap text-slate-500 dark:text-slate-400"
                                >
                                    {{ faq.answer }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
