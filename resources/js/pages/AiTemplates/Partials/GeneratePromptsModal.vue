<script setup lang="ts">
import { ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Sparkles, Loader2 } from 'lucide-vue-next';
import { generatePrompts } from '@/routes/ai-templates';

const props = defineProps<{
    open: boolean;
    initialBrief: string | null;
    hasExistingPrompts: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'apply', payload: { brief: string; systemPrompt: string; userPrompt: string }): void;
}>();

const brief = ref(props.initialBrief ?? '');
const generating = ref(false);
const error = ref<string | null>(null);
const result = ref<{ systemPrompt: string; userPrompt: string } | null>(null);

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        brief.value = props.initialBrief ?? '';
        result.value = null;
        error.value = null;
    }
});

const getCsrfToken = (): string => {
    const match = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

const generate = async () => {
    generating.value = true;
    error.value = null;

    try {
        const response = await fetch(generatePrompts().url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({ brief: brief.value }),
        });

        if (!response.ok) {
            const data = await response.json().catch(() => null);
            error.value = data?.message ?? 'Generation failed. Please try again.';
            return;
        }

        const data = await response.json();
        result.value = { systemPrompt: data.system_prompt ?? '', userPrompt: data.user_prompt ?? '' };
    } catch {
        error.value = 'Generation failed. Please try again.';
    } finally {
        generating.value = false;
    }
};

const apply = () => {
    if (!result.value) { return; }

    emit('apply', {
        brief: brief.value,
        systemPrompt: result.value.systemPrompt,
        userPrompt: result.value.userPrompt,
    });
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('close')">
        <DialogContent class="sm:max-w-[600px]">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Sparkles class="h-4 w-4 text-projector-primary-500" />
                    Generate with AI
                </DialogTitle>
                <DialogDescription>
                    Describe what this transformation should do, and AI will draft the system instructions and user prompt for you.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4">
                <div class="space-y-2">
                    <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400">What should this transformation do?</Label>
                    <Textarea
                        v-model="brief"
                        placeholder="e.g. Turn raw meeting notes into a polished user story with acceptance criteria"
                        class="min-h-24 rounded-xl"
                        :disabled="generating"
                    />
                </div>

                <div v-if="error" class="text-[10px] font-bold text-red-500 uppercase px-1">{{ error }}</div>

                <div v-if="result" class="space-y-4 pt-2 border-t border-gray-100 dark:border-gray-800">
                    <div class="space-y-2">
                        <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400">Drafted System Instructions</Label>
                        <div class="max-h-32 overflow-y-auto rounded-xl border border-gray-200 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-900/50 p-3 text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ result.systemPrompt }}</div>
                    </div>
                    <div class="space-y-2">
                        <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400">Drafted User Prompt</Label>
                        <div class="max-h-32 overflow-y-auto rounded-xl border border-gray-200 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-900/50 p-3 text-sm font-mono text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ result.userPrompt }}</div>
                    </div>
                    <p v-if="hasExistingPrompts" class="text-[10px] font-bold text-amber-600 dark:text-amber-400 uppercase tracking-wide px-1">
                        Applying will replace your current System Instructions and User Prompt.
                    </p>
                </div>
            </div>

            <DialogFooter class="gap-2 sm:gap-4">
                <Button type="button" variant="outline" @click="emit('close')" :disabled="generating">
                    Cancel
                </Button>
                <Button
                    v-if="!result"
                    type="button"
                    :disabled="generating || !brief.trim()"
                    @click="generate"
                >
                    <Loader2 v-if="generating" class="h-4 w-4 animate-spin" />
                    {{ generating ? 'Generating…' : 'Generate' }}
                </Button>
                <template v-else>
                    <Button type="button" variant="outline" :disabled="generating" @click="generate">
                        Regenerate
                    </Button>
                    <Button type="button" @click="apply">
                        Apply
                    </Button>
                </template>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
