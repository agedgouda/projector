<script setup lang="ts">
import { Button } from '@/components/ui/button';
import projectDocumentsRoutes from '@/routes/projects/documents/index';
import { router } from '@inertiajs/vue3';
import { ArrowLeft, Edit2, Save, Trash2, X } from 'lucide-vue-next';
import { computed } from 'vue';

type AncestorDoc = {
    id: string | number;
    name: string;
    parent?: AncestorDoc | null;
};

const props = defineProps<{
    project: Project;
    item: {
        id?: string | number;
        name: string;
        parent?: AncestorDoc | null;
    };
    isEditing: boolean;
    saveLabel?: string;
    isSaving?: boolean;
    // Only set on the document edit page (Documents/Show.vue), to match the fixed w-24 the
    // Cancel/Edit toggle button below already uses — left unset on the create page, whose
    // save label is the dynamic (and often much longer) "Create <Type>".
    saveButtonClass?: string;
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

const getFrom = () =>
    new URLSearchParams(window.location.search).get('from') ?? '';

const navigateToAncestor = (ancestorId: string | number) => {
    const baseUrl = projectDocumentsRoutes.show({
        project: String(props.project.id),
        document: String(ancestorId),
    }).url;
    const from = getFrom();
    router.get(from ? `${baseUrl}?from=${encodeURIComponent(from)}` : baseUrl);
};

const emit = defineEmits(['back', 'toggle-edit', 'delete', 'save']);
</script>

<template>
    <nav class="border-b bg-light-background px-6 py-4 dark:border-gray-800">
        <div class="flex items-center justify-between">
            <div
                class="flex items-center gap-4 text-sm text-slate-900 dark:text-slate-400"
            >
                <button
                    @click="emit('back')"
                    class="flex cursor-pointer items-center gap-2 border-0 bg-transparent p-0 transition-colors hover:text-projector-primary-600"
                >
                    <ArrowLeft class="h-3 w-3" />
                    {{ project.name }}
                </button>
                <template v-for="ancestor in ancestors" :key="ancestor.id">
                    <span class="text-slate-300">/</span>
                    <button
                        @click="navigateToAncestor(ancestor.id)"
                        class="max-w-[200px] cursor-pointer truncate border-0 bg-transparent p-0 transition-colors hover:text-projector-primary-600"
                    >
                        {{ ancestor.name }}
                    </button>
                </template>
            </div>

            <div v-if="!project.inactive" class="flex items-center gap-2">
                <slot v-if="isEditing" name="extra-actions" />

                <Button
                    variant="outline"
                    size="sm"
                    @click="emit('toggle-edit')"
                    class="relative flex h-8 w-24 items-center justify-center text-[10px] font-black tracking-widest uppercase transition-all duration-200"
                >
                    <div
                        class="absolute left-3 flex items-center justify-center"
                    >
                        <component
                            :is="isEditing ? X : Edit2"
                            class="h-3 w-3 transition-transform duration-200"
                            :class="{ 'rotate-90': isEditing }"
                        />
                    </div>
                    <span class="ml-4">{{
                        isEditing ? 'Cancel' : 'Edit'
                    }}</span>
                </Button>

                <Button
                    v-if="isEditing"
                    size="sm"
                    :disabled="isSaving"
                    @click="emit('save')"
                    :class="[
                        'h-8 bg-projector-primary-600 px-4 text-[10px] font-black tracking-widest text-white uppercase hover:bg-projector-primary-700',
                        saveButtonClass,
                    ]"
                >
                    <Save class="mr-1.5 h-3 w-3" />
                    {{ saveLabel ?? 'Save' }}
                </Button>

                <Button
                    v-if="!isEditing"
                    variant="ghost"
                    size="icon"
                    @click="emit('delete')"
                    class="flex h-8 w-8 items-center justify-center p-0 text-slate-400 hover:bg-red-50 hover:text-red-600"
                >
                    <Trash2 class="h-4 w-4" />
                </Button>
            </div>
        </div>
    </nav>
</template>
