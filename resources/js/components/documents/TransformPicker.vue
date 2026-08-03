<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useTransitionOptions } from '@/composables/useTransitionOptions';
import { computed, ref } from 'vue';

const props = defineProps<{
    projectId: string;
    documentId: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    (
        e: 'run',
        payload: {
            toKey?: string;
            aiTemplateId: number;
            singleOutput?: boolean;
            projectTypeId?: string;
        },
    ): void;
}>();

const { aiTemplates, load } = useTransitionOptions(
    props.projectId,
    props.documentId,
);
void load();

const selectedTemplateId = ref('');

// Guards against a rapid double-click firing this twice before the parent's own
// "processing" state has had a chance to propagate back down and disable us.
const hasRun = ref(false);

const canRun = computed(() => !hasRun.value && !!selectedTemplateId.value);

const run = () => {
    if (!canRun.value) return;

    hasRun.value = true;

    emit('run', { aiTemplateId: Number(selectedTemplateId.value) });
};
</script>

<template>
    <div class="w-72 space-y-3 p-3">
        <div class="space-y-1.5">
            <div class="flex max-h-48 flex-col gap-1 overflow-y-auto">
                <button
                    v-for="template in aiTemplates"
                    :key="template.id"
                    type="button"
                    class="rounded-md px-2.5 py-1.5 text-left text-[11px] transition-colors"
                    :class="
                        String(template.id) === selectedTemplateId
                            ? 'bg-projector-primary-50 font-bold text-projector-primary-700 dark:bg-projector-primary-950/30 dark:text-projector-primary-400'
                            : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'
                    "
                    @click="selectedTemplateId = String(template.id)"
                >
                    {{ template.name }}
                </button>
            </div>
        </div>

        <Button
            size="sm"
            class="h-8 w-full text-[10px] font-black tracking-wider uppercase"
            :disabled="!canRun || disabled"
            @click="run"
        >
            Run
        </Button>
    </div>
</template>
