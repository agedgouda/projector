<script setup lang="ts">
import { ref } from 'vue';
import { useForm, router, Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';

// Define the incoming props from DnaController
const { documents } = defineProps<{
    documents: Array<{
        id: number;
        content: string;
        created_at: string;
    }>;
    searchResults?: Array<{ content: string }>;
}>();


// Form for adding new DNA snippets
const form = useForm({
    content: '',
});

// Edit Mode State
const editingId = ref<number | null>(null);
const editForm = useForm({
    content: '',
});

const startEdit = (doc: { id: number; content: string }) => {
    editingId.value = doc.id;
    editForm.content = doc.content;
};

const cancelEdit = () => {
    editingId.value = null;
    editForm.reset();
};

const submitUpdate = (id: number) => {
    editForm.patch(`/dna/${id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
            editForm.reset();
        },
    });
};

const successMessage = ref(false);

const submit = () => {
    form.post('/dna', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('content');
            successMessage.value = true;
            setTimeout(() => successMessage.value = false, 3000);
        },
    });
};

const deleteSnippet = (id: number) => {
    if (confirm('Are you sure you want to remove this DNA snippet?')) {
        router.delete(`/dna/${id}`);
    }
};

const searchForm = useForm({
    query: '',
});

const performSearch = () => {
    searchForm.post('/dna/search', {
        preserveState: true,
        preserveScroll: true,
    });
};



</script>

<template>
    <Head title="Project DNA Dashboard" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">🧬 Project DNA</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">

                <div class="p-6 bg-white shadow sm:rounded-lg border border-slate-200">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Add New Knowledge</h3>
                    <form @submit.prevent="submit" class="space-y-4">
                        <textarea
                            v-model="form.content"
                            rows="4"
                            class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 shadow-sm"
                            placeholder="Describe a project requirement, rule, or piece of architecture..."
                            :disabled="form.processing"
                        ></textarea>

                        <div class="flex items-center justify-between">
                            <button
                                type="submit"
                                :disabled="form.processing || !form.content"
                                class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                            >
                                <span v-if="form.processing">Vectorizing...</span>
                                <span v-else>Save Snippet</span>
                            </button>

                            <Transition enter-active-class="transition ease-in-out" enter-from-class="opacity-0" leave-active-class="transition ease-in-out" leave-to-class="opacity-0">
                                <p v-if="successMessage" class="text-sm text-green-600">Saved successfully.</p>
                            </Transition>
                        </div>
                    </form>
                </div>

                <div class="p-6 bg-white shadow sm:rounded-lg border border-slate-200">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Existing Knowledge Base</h3>

                    <div v-if="documents.length === 0" class="text-center py-8 text-gray-500 italic">
                        No DNA snippets found. Start by adding one above.
                    </div>

                    <div v-else class="space-y-4">

<div class="p-6 bg-slate-900 shadow sm:rounded-lg border border-slate-700 text-white">
    <h3 class="text-lg font-medium mb-4 text-indigo-300">🔍 Search Knowledge Base</h3>
    <div class="flex gap-2">
        <input
            v-model="searchForm.query"
            type="text"
            placeholder="Ask a question (e.g., 'What is our tech stack?')"
            class="flex-1 rounded-lg border-slate-700 bg-slate-800 text-white focus:ring-indigo-500"
            @keyup.enter="performSearch"
        />
        <button @click="performSearch" class="bg-indigo-600 px-4 py-2 rounded-lg font-bold">Ask</button>
    </div>

    <div v-if="searchResults" class="mt-4 space-y-3">
        <div v-for="(res, i) in searchResults" :key="i" class="p-3 bg-slate-800 rounded border border-indigo-500/30">
            <p class="text-sm text-slate-300">{{ res.content }}</p>
        </div>
    </div>
</div>



                        <div v-for="doc in documents" :key="doc.id" class="p-4 rounded-lg bg-slate-50 border border-slate-100 group">

                            <div v-if="editingId === doc.id" class="space-y-3">
                                <textarea
                                    v-model="editForm.content"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-gray-700"
                                    rows="3"
                                ></textarea>
                                <div class="flex space-x-2">
                                    <button
                                        @click="submitUpdate(doc.id)"
                                        :disabled="editForm.processing"
                                        class="px-3 py-1 bg-indigo-600 text-white text-xs font-semibold rounded hover:bg-indigo-700"
                                    >
                                        {{ editForm.processing ? 'Saving...' : 'Save' }}
                                    </button>
                                    <button
                                        @click="cancelEdit"
                                        class="px-3 py-1 bg-white border border-gray-300 text-gray-700 text-xs font-semibold rounded hover:bg-gray-50"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </div>

                            <div v-else class="flex justify-between items-start">
                                <div class="flex-1">
                                    <p class="text-gray-700 whitespace-pre-wrap">{{ doc.content }}</p>
                                    <div class="flex items-center space-x-3 mt-2">
                                        <span class="text-xs text-gray-400">Added on {{ new Date(doc.created_at).toLocaleDateString() }}</span>
                                        <button @click="startEdit(doc)" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">Edit</button>
                                    </div>
                                </div>
                                <button
                                    @click="deleteSnippet(doc.id)"
                                    class="ml-4 text-red-400 hover:text-red-600 opacity-0 group-hover:opacity-100 transition-opacity"
                                    title="Delete snippet"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </div>

                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
