<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { ArrowLeft, Edit2, Trash2, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import projectDocumentsRoutes from '@/routes/projects/documents/index';

type AncestorDoc = { id: string | number; name: string; parent?: AncestorDoc | null };

const props = defineProps<{
    project: Project;
    item: {
        id?: string | number;
        name: string;
        parent?: AncestorDoc | null;
    };
    isEditing: boolean;
}>();

const ancestors = computed(() => {
    const chain: { id: string | number; name: string }[] = [];
    let current = props.item.parent;
    while (current) {
        chain.unshift({ id: current.id, name: current.name });
        current = current.parent ?? null;
    }
    return chain;
});

const getFrom = () => new URLSearchParams(window.location.search).get('from') ?? '';

const navigateToAncestor = (ancestorId: string | number) => {
    const baseUrl = projectDocumentsRoutes.show({
        project: String(props.project.id),
        document: String(ancestorId),
    }).url;
    const from = getFrom();
    router.get(from ? `${baseUrl}?from=${encodeURIComponent(from)}` : baseUrl);
};

const emit = defineEmits(['back', 'toggle-edit', 'delete']);
</script>

<template>
    <nav class="border-b bg-light-background dark:border-gray-800 px-8 py-4">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-4 text-sm text-slate-500 dark:text-slate-400">
                <button
                    @click="emit('back')"
                    class="hover:text-indigo-600 transition-colors flex items-center gap-2 cursor-pointer bg-transparent border-0 p-0"
                >
                    <ArrowLeft class="w-3 h-3" />
                    {{ project.name }}
                </button>
                <template v-for="ancestor in ancestors" :key="ancestor.id">
                    <span class="text-slate-300">/</span>
                    <button
                        @click="navigateToAncestor(ancestor.id)"
                        class="hover:text-indigo-600 transition-colors cursor-pointer bg-transparent border-0 p-0 truncate max-w-[200px]"
                    >
                        {{ ancestor.name }}
                    </button>
                </template>
            </div>

            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    @click="emit('toggle-edit')"
                    class="relative h-8 w-24 text-[10px] font-black uppercase tracking-widest flex items-center justify-center transition-all duration-200"
                >
                    <div class="absolute left-3 flex items-center justify-center">
                        <component
                            :is="isEditing ? X : Edit2"
                            class="h-3 w-3 transition-transform duration-200"
                            :class="{ 'rotate-90': isEditing }"
                        />
                    </div>
                    <span class="ml-4">{{ isEditing ? 'Cancel' : 'Edit' }}</span>
                </Button>

                <Button
                    variant="ghost"
                    size="icon"
                    @click="emit('delete')"
                    class="h-8 w-8 p-0 text-slate-400 hover:text-red-600 hover:bg-red-50 flex items-center justify-center"
                >
                    <Trash2 class="h-4 w-4" />
                </Button>
            </div>
        </div>
    </nav>
</template>
