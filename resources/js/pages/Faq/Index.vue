<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { FLAT_ACTION_BUTTON } from '@/lib/flat-ui';
import bugReportsRoutes from '@/routes/bug-reports/index';
import faqRoutes from '@/routes/faq/index';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    BarChart3,
    Building2,
    CalendarDays,
    Check,
    CircleHelp,
    Cloud,
    Edit2,
    FileText,
    LayoutGrid,
    ListChecks,
    MessageCircle,
    MessageSquare,
    Mic,
    Minus,
    Plug,
    Plus,
    Search,
    Settings,
    SlidersHorizontal,
    Trash2,
    Upload,
    User,
    Users,
    Workflow,
    X,
    type LucideIcon,
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

// Help-center layout: categories listed down the left (in FAQ order), the selected one's
// questions on the right. Each category uses the icon its area already has in the app's menus —
// the sidebar (Dashboard, Status
// Meetings, Projects, Import), the user menu (Settings, Users, Transformations), the
// organization page's Configuration tab, and the Import page's Document / Task List options.
// Areas with no menu entry of their own keep a descriptive icon; a category an admin adds later
// gets the FAQ's own help icon.
const CATEGORY_ICONS: Record<string, LucideIcon> = {
    'Your Account': Settings,
    'User Management': User,
    'Organization Settings': SlidersHorizontal,
    Projects: Users,
    'Tasks & Events': ListChecks,
    Documents: FileText,
    Transformations: Workflow,
    Importing: Upload,
    'Transcripts & Meeting Notes': Mic,
    'Status Meetings': CalendarDays,
    Reports: BarChart3,
    'Dashboard & Help': LayoutGrid,
    Slack: MessageSquare,
    Dropbox: Cloud,
};
const iconFor = (category: string): LucideIcon =>
    CATEGORY_ICONS[category] ?? CircleHelp;

const isSearching = computed(() => searchQuery.value.trim() !== '');

// The left-hand list: five high-level groups, each gathering several FAQ categories (shown as
// subheadings inside it, in the order listed here). Icons come from the app's menus where the
// area has one — the user menu's Users, the sidebar's Projects, Status Meetings and
// Organizations; Integrations has no menu entry, so it gets a plug. A category no group lists
// (e.g. one an admin adds later) shows as a group of its own rather than disappearing.
interface FaqGroup {
    name: string;
    icon: LucideIcon;
    categories: string[];
}
const FAQ_GROUPS: FaqGroup[] = [
    {
        name: 'Account & Users',
        icon: User,
        categories: ['Your Account', 'User Management', 'Dashboard & Help'],
    },
    {
        name: 'Projects & Docs',
        icon: Users,
        categories: [
            'Projects',
            'Documents',
            'Reports',
            'Tasks & Events',
            'Importing',
        ],
    },
    {
        name: 'Transcripts & Meetings',
        icon: CalendarDays,
        categories: ['Transcripts & Meeting Notes', 'Status Meetings'],
    },
    {
        name: 'Integrations',
        icon: Plug,
        categories: ['Slack', 'Dropbox'],
    },
    {
        name: 'Organization Settings',
        icon: Building2,
        categories: ['Organization Settings', 'Transformations'],
    },
];

const groups = computed<FaqGroup[]>(() => {
    const present = new Set(props.faqs.map((faq) => faq.category));
    const listed = new Set(FAQ_GROUPS.flatMap((group) => group.categories));
    const known = FAQ_GROUPS.map((group) => ({
        ...group,
        categories: group.categories.filter((category) =>
            present.has(category),
        ),
    })).filter((group) => group.categories.length);
    const extra = [...present]
        .filter((category) => !listed.has(category))
        .map((category) => ({
            name: category,
            icon: iconFor(category),
            categories: [category],
        }));
    return [...known, ...extra];
});

const selectedGroup = ref<string | null>(null);
const activeGroup = computed(
    () =>
        groups.value.find((group) => group.name === selectedGroup.value) ??
        groups.value[0] ??
        null,
);

const selectGroup = (name: string) => {
    selectedGroup.value = name;
    searchQuery.value = '';
};

// What the right-hand panel shows: the selected group, or — while searching — every group with
// a match; within each, its categories' (matching) questions under their own subheadings.
const visibleGroups = computed(() => {
    const source = isSearching.value ? filtered.value : props.faqs;
    const shown = isSearching.value
        ? groups.value
        : activeGroup.value
          ? [activeGroup.value]
          : [];
    return shown
        .map((group) => ({
            ...group,
            sections: group.categories
                .map((category) => ({
                    category,
                    items: source.filter((faq) => faq.category === category),
                }))
                .filter((section) => section.items.length),
        }))
        .filter((group) => group.sections.length);
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
        <div
            class="mr-[max(0px,calc((100%-72rem)/2))] w-auto space-y-12 px-6 pt-6 pb-16"
        >
            <!-- Help-center layout: centered title and search, then categories beside their questions. -->
            <div class="relative space-y-6 pt-6 text-center">
                <Button
                    v-if="isSuperAdmin"
                    @click="showAddForm = !showAddForm"
                    class="ml-auto flex h-10 rounded-xl bg-projector-primary-600 px-4 text-[10px] font-black tracking-widest whitespace-nowrap text-white uppercase hover:bg-projector-primary-700 sm:absolute sm:top-0 sm:right-0"
                >
                    <Plus class="mr-1.5 h-4 w-4" /> Add FAQ
                </Button>
                <div>
                    <h1
                        class="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl dark:text-white"
                    >
                        Frequently Asked Questions
                    </h1>
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                        Find answers to common questions about Projector.
                    </p>
                </div>
                <div class="relative mx-auto w-full max-w-2xl">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-4 h-4 w-4 -translate-y-1/2 text-slate-500"
                    />
                    <Input
                        v-model="searchQuery"
                        placeholder="Search FAQs..."
                        class="h-12 rounded-xl border-gray-200 bg-white pl-11 text-base shadow-sm dark:border-gray-700 dark:bg-slate-950"
                    />
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

            <div
                class="grid gap-8 md:grid-cols-[15rem_minmax(0,1fr)] md:gap-10"
            >
                <!-- Groups -->
                <nav
                    class="flex gap-1 overflow-x-auto md:sticky md:top-6 md:flex-col md:self-start md:overflow-visible"
                    aria-label="FAQ categories"
                >
                    <button
                        v-for="group in groups"
                        :key="group.name"
                        type="button"
                        :class="[
                            'flex shrink-0 items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium transition-colors',
                            !isSearching && group.name === activeGroup?.name
                                ? 'bg-projector-primary-50 text-projector-primary-700 dark:bg-projector-primary-950/40 dark:text-projector-primary-300'
                                : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5',
                        ]"
                        :aria-current="
                            !isSearching && group.name === activeGroup?.name
                                ? 'true'
                                : undefined
                        "
                        @click="selectGroup(group.name)"
                    >
                        <component :is="group.icon" class="h-4 w-4 shrink-0" />
                        {{ group.name }}
                    </button>
                </nav>

                <div class="min-w-0 space-y-10">
                    <!-- Empty state -->
                    <div
                        v-if="visibleGroups.length === 0"
                        class="rounded-3xl border-2 border-dashed border-gray-100 py-24 text-center dark:border-gray-800"
                    >
                        <Search class="mx-auto mb-4 h-10 w-10 text-gray-200" />
                        <h3
                            class="text-xs font-black tracking-widest text-gray-400 uppercase"
                        >
                            No results found
                        </h3>
                    </div>

                    <!-- Questions: the selected group, or every group with a match while searching -->
                    <section
                        v-for="group in visibleGroups"
                        :key="group.name"
                        class="space-y-6"
                    >
                        <h2
                            class="flex items-center gap-3 text-xl font-semibold tracking-tight text-gray-900 dark:text-white"
                        >
                            <component
                                :is="group.icon"
                                class="h-5 w-5 text-gray-500 dark:text-gray-400"
                            />
                            {{ group.name }}
                        </h2>

                        <div
                            v-for="{ category, items } in group.sections"
                            :key="category"
                            class="space-y-1"
                        >
                            <h3
                                v-if="
                                    group.sections.length > 1 ||
                                    group.name !== category
                                "
                                class="flex items-center gap-2 px-4 pt-2 text-[11px] font-black tracking-widest text-gray-400 uppercase dark:text-gray-500"
                            >
                                <component
                                    :is="iconFor(category)"
                                    class="h-3.5 w-3.5"
                                />
                                {{ category }}
                            </h3>
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
                                            <Input
                                                v-model="editForm.category"
                                            />
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

                                <!-- View mode: a divider row, or a raised card while open -->
                                <div
                                    v-else
                                    :class="[
                                        'group transition-colors',
                                        expandedIds.has(faq.id)
                                            ? 'my-2 rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/5'
                                            : 'border-b border-gray-200 dark:border-white/10',
                                    ]"
                                >
                                    <button
                                        type="button"
                                        class="flex w-full items-center justify-between gap-4 px-4 py-4 text-left"
                                        :aria-expanded="expandedIds.has(faq.id)"
                                        @click="toggle(faq.id)"
                                    >
                                        <span
                                            class="text-[15px] font-medium text-gray-900 dark:text-gray-100"
                                            >{{ faq.question }}</span
                                        >
                                        <div
                                            class="flex shrink-0 items-center gap-1"
                                        >
                                            <template v-if="isSuperAdmin">
                                                <button
                                                    type="button"
                                                    @click.stop="startEdit(faq)"
                                                    :class="FLAT_ACTION_BUTTON"
                                                >
                                                    <Edit2
                                                        class="h-3.5 w-3.5"
                                                    />
                                                </button>
                                                <button
                                                    type="button"
                                                    @click.stop="deleteFaq(faq)"
                                                    class="flex h-7 w-7 items-center justify-center rounded-md text-slate-400 opacity-0 transition-colors group-hover:opacity-100 hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-950/30"
                                                >
                                                    <Trash2
                                                        class="h-3.5 w-3.5"
                                                    />
                                                </button>
                                            </template>
                                            <span
                                                :class="[
                                                    'flex h-7 w-7 items-center justify-center rounded-md',
                                                    expandedIds.has(faq.id)
                                                        ? 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200'
                                                        : 'text-gray-500 dark:text-gray-400',
                                                ]"
                                            >
                                                <Minus
                                                    v-if="
                                                        expandedIds.has(faq.id)
                                                    "
                                                    class="h-4 w-4"
                                                />
                                                <Plus v-else class="h-4 w-4" />
                                            </span>
                                        </div>
                                    </button>
                                    <div
                                        v-if="expandedIds.has(faq.id)"
                                        class="px-4 pb-5"
                                    >
                                        <p
                                            class="text-[14px] leading-relaxed whitespace-pre-wrap text-gray-600 dark:text-gray-300"
                                        >
                                            {{ faq.answer }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Still stuck -->
                    <div
                        class="flex flex-col items-start justify-between gap-4 rounded-xl border border-gray-200 bg-gray-50 px-6 py-5 sm:flex-row sm:items-center dark:border-white/10 dark:bg-white/5"
                    >
                        <p
                            class="text-base font-medium text-gray-900 dark:text-gray-100"
                        >
                            Still can't find what you're looking for?
                        </p>
                        <Link
                            :href="bugReportsRoutes.create().url"
                            class="inline-flex h-10 items-center gap-2 rounded-lg bg-projector-primary-100 px-5 text-sm font-semibold text-projector-primary-700 transition-colors hover:bg-projector-primary-200 dark:bg-projector-primary-900/40 dark:text-projector-primary-200 dark:hover:bg-projector-primary-900/60"
                        >
                            <MessageCircle class="h-4 w-4" />
                            Get In Touch
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
