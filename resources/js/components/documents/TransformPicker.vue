<script setup lang="ts">
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useTransitionOptions } from '@/composables/useTransitionOptions';

const props = defineProps<{
    projectId: string;
    documentId: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    (e: 'run', payload: { toKey?: string; aiTemplateId: number; singleOutput?: boolean; projectTypeId?: string }): void;
}>();

const { protocolOptions, aiTemplates, load } = useTransitionOptions(props.projectId, props.documentId);
void load();

type Mode = 'protocol' | 'template';
const mode = ref<Mode>('template');

const selectedProtocolId = ref('');
const selectedTemplateId = ref('');

const selectedProtocol = computed(() => protocolOptions.value.find(p => p.projectTypeId === selectedProtocolId.value));

const canRun = computed(() => {
    if (mode.value === 'protocol') return !!selectedProtocolId.value;
    return !!selectedTemplateId.value;
});

const run = () => {
    if (!canRun.value) return;

    if (mode.value === 'protocol' && selectedProtocol.value) {
        emit('run', {
            toKey: selectedProtocol.value.toKey,
            aiTemplateId: selectedProtocol.value.aiTemplateId,
            singleOutput: selectedProtocol.value.singleOutput,
            projectTypeId: selectedProtocol.value.projectTypeId,
        });
        return;
    }

    emit('run', { aiTemplateId: Number(selectedTemplateId.value) });
};
</script>

<template>
    <div class="p-3 space-y-3 w-72">
        <div class="flex rounded-lg bg-gray-100 dark:bg-gray-900 p-0.5 text-[10px] font-black uppercase tracking-wider">
            <button
                type="button"
                class="flex-1 rounded-md py-1.5 transition-colors"
                :class="mode === 'template' ? 'bg-white dark:bg-gray-800 shadow-sm text-gray-900 dark:text-white' : 'text-gray-400'"
                @click="mode = 'template'"
            >
                Transformation
            </button>
            <button
                type="button"
                class="flex-1 rounded-md py-1.5 transition-colors"
                :class="mode === 'protocol' ? 'bg-white dark:bg-gray-800 shadow-sm text-gray-900 dark:text-white' : 'text-gray-400'"
                @click="mode = 'protocol'"
            >
                Pipeline
            </button>
        </div>

        <div v-if="mode === 'template'" class="space-y-1.5">
            <p class="text-[9px] font-black uppercase tracking-wider text-gray-400">Generate:</p>
            <div class="flex flex-col gap-1 max-h-48 overflow-y-auto">
                <button
                    v-for="template in aiTemplates"
                    :key="template.id"
                    type="button"
                    class="text-left px-2.5 py-1.5 rounded-md text-[11px] transition-colors"
                    :class="String(template.id) === selectedTemplateId
                        ? 'bg-projector-primary-50 text-projector-primary-700 font-bold dark:bg-projector-primary-950/30 dark:text-projector-primary-400'
                        : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800'"
                    @click="selectedTemplateId = String(template.id)"
                >
                    {{ template.name }}
                </button>
            </div>
        </div>

        <div v-else class="flex flex-col gap-1 max-h-48 overflow-y-auto">
            <button
                v-for="protocol in protocolOptions"
                :key="protocol.projectTypeId"
                type="button"
                class="text-left px-2.5 py-1.5 rounded-md text-[11px] transition-colors"
                :class="protocol.projectTypeId === selectedProtocolId
                    ? 'bg-projector-primary-50 text-projector-primary-700 font-bold dark:bg-projector-primary-950/30 dark:text-projector-primary-400'
                    : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800'"
                @click="selectedProtocolId = protocol.projectTypeId"
            >
                {{ protocol.name }}
            </button>
            <p v-if="protocolOptions.length === 0" class="text-[10px] text-gray-400 px-2.5 py-1.5">
                No protocol defines a next step from here.
            </p>
        </div>

        <Button
            size="sm"
            class="w-full h-8 text-[10px] font-black uppercase tracking-wider"
            :disabled="!canRun || disabled"
            @click="run"
        >
            Run
        </Button>
    </div>
</template>
