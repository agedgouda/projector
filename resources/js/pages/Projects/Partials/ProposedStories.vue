<script setup lang="ts">
import { computed,ref } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import projectRoutes from '@/routes/projects/index';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    projectId: string | number;
}>();

const page = usePage();
const isGenerating = ref(false);

// Casting to any to solve the TypeScript '{}' error
const aiResults = computed(() => (page.props as any).flash?.aiResults || null);
const stories = computed(() => aiResults.value?.mock_response || []);

const generate = () => {
    const url = projectRoutes.generate.url({
        project: props.projectId.toString()
    });

    router.post(url, {}, {
        preserveScroll: true,
        onStart: () => { isGenerating.value = true },
        onFinish: () => { isGenerating.value = false },
    });
};

</script>

<template>

    freene
    <section class="mt-8">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-900">AI Discovery</h3>
            <Button
                @click="generate"
                :disabled="isGenerating"
                class="..."
            >
                {{ isGenerating ? 'Analyzing...' : (stories.length > 0 ? 'Re-Generate Stories' : 'Extract User Stories') }}
            </Button>
        </div>

        <div v-if="stories.length > 0" class="bg-white shadow overflow-hidden sm:rounded-lg border border-gray-200">
            <div class="px-4 py-5 sm:px-6 bg-gray-50 border-b border-gray-200">
                <p class="mt-1 max-w-2xl text-sm text-gray-500">
                    The following stories were extracted from your <strong>Notes</strong>.
                </p>
            </div>
            <ul role="list" class="divide-y divide-gray-200">
                <li v-for="(story, index) in stories" :key="index" class="px-4 py-5 sm:px-6 hover:bg-gray-50">
                    <h4 class="text-md font-bold text-indigo-600">{{ story.title }}</h4>
                    <p class="text-sm text-gray-700 italic mt-1">"{{ story.story }}"</p>
                    <div class="mt-4">
                        <h5 class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Acceptance Criteria</h5>
                        <ul class="space-y-1">
                            <li v-for="item in story.criteria" :key="item" class="text-xs text-gray-500 flex items-start">
                                <span class="mr-2 text-indigo-400">•</span> {{ item }}
                            </li>
                        </ul>
                    </div>
                </li>
            </ul>
        </div>

        <div v-else class="border-2 border-dashed border-gray-200 rounded-lg p-12 text-center">
            <h3 class="mt-2 text-sm font-medium text-gray-900">No stories generated</h3>
            <p class="mt-1 text-sm text-gray-500">Click the button above to analyze your intake notes.</p>
        </div>
    </section>
</template>
