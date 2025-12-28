<script setup lang="ts">
import { useForm} from '@inertiajs/vue3';
import { ref } from 'vue';

const successMessage = ref(false);
const form = useForm({
    content: '',
    type: 'dna', // default to dna
});

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
</script>

<template>
    <div class="max-w-2xl mx-auto p-6 bg-white rounded-xl shadow-sm border border-slate-200">
        <h2 class="text-xl font-bold text-slate-800 mb-4">🧬 Project DNA Input</h2>
        <p class="text-sm text-slate-500 mb-6">
            Add technical constraints, tech stack details, or project rules.
            These will be converted to vectors for the AI.
        </p>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <textarea
                    v-model="form.content"
                    rows="5"
                    class="w-full rounded-lg border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"
                    placeholder="e.g. Primary database is PostgreSQL. Use Tailwind CSS for all styling. Auth is handled by Laravel Fortify..."
                    :disabled="form.processing"
                ></textarea>
                <div v-if="form.errors.content" class="text-red-500 text-xs mt-1">
                    {{ form.errors.content }}
                </div>
            </div>

            <div class="flex items-center justify-between">
                <span v-if="successMessage" class="text-green-600 text-sm font-medium animate-pulse">
                    ✅ Snippet Vectorized & Saved!
                </span>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="ml-auto px-4 py-2 bg-indigo-600 text-white rounded-lg font-semibold hover:bg-indigo-700 disabled:opacity-50 transition"
                >
                    {{ form.processing ? 'Saving...' : 'Save DNA Snippet' }}
                </button>
            </div>
        </form>
    </div>
</template>
