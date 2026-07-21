<script setup lang="ts">
import { computed, ref } from 'vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
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
const mode = ref<Mode>('protocol');

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
    <div class="p-3 space-y-3 w-64">
        <div class="flex rounded-lg bg-gray-100 dark:bg-gray-900 p-0.5 text-[10px] font-black uppercase tracking-wider">
            <button
                type="button"
                class="flex-1 rounded-md py-1.5 transition-colors"
                :class="mode === 'protocol' ? 'bg-white dark:bg-gray-800 shadow-sm text-gray-900 dark:text-white' : 'text-gray-400'"
                @click="mode = 'protocol'"
            >
                Use Protocol
            </button>
            <button
                type="button"
                class="flex-1 rounded-md py-1.5 transition-colors"
                :class="mode === 'template' ? 'bg-white dark:bg-gray-800 shadow-sm text-gray-900 dark:text-white' : 'text-gray-400'"
                @click="mode = 'template'"
            >
                Pick AI Template
            </button>
        </div>

        <div v-if="mode === 'protocol'" class="space-y-1">
            <Label class="text-[9px] font-black uppercase tracking-wider text-gray-400">Protocol</Label>
            <Select v-model="selectedProtocolId">
                <SelectTrigger class="h-8 text-[11px]">
                    <SelectValue placeholder="Choose protocol..." />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="protocol in protocolOptions"
                        :key="protocol.projectTypeId"
                        :value="protocol.projectTypeId"
                        class="text-[11px]"
                    >
                        {{ protocol.name }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <p v-if="protocolOptions.length === 0" class="text-[10px] text-gray-400 pt-1">
                No protocol defines a next step from here.
            </p>
        </div>

        <div v-else class="space-y-1">
            <Label class="text-[9px] font-black uppercase tracking-wider text-gray-400">AI Template</Label>
            <Select v-model="selectedTemplateId">
                <SelectTrigger class="h-8 text-[11px]">
                    <SelectValue placeholder="Choose template..." />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="template in aiTemplates" :key="template.id" :value="String(template.id)" class="text-[11px]">
                        {{ template.name }}
                    </SelectItem>
                </SelectContent>
            </Select>
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
