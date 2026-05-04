<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Sparkles } from 'lucide-vue-next';

const props = defineProps<{
    open: boolean;
    limitKey?: 'users' | 'clients' | 'projects' | 'ai_docs';
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const LIMIT_LABELS: Record<string, string> = {
    users: 'team members',
    clients: 'clients',
    projects: 'projects',
    ai_docs: 'AI-processed documents this month',
};

const limitLabel = props.limitKey ? LIMIT_LABELS[props.limitKey] : 'items';
</script>

<template>
    <Dialog :open="open" @update:open="emit('close')">
        <DialogContent class="sm:max-w-[440px]">
            <DialogHeader>
                <div class="flex items-center gap-3 mb-1">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-50 dark:bg-indigo-900/30">
                        <Sparkles class="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
                    </div>
                    <DialogTitle class="text-lg font-black">Upgrade to Pro</DialogTitle>
                </div>
                <DialogDescription class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed">
                    You've reached the limit for <strong class="text-slate-800 dark:text-slate-200">{{ limitLabel }}</strong> on the Free plan.
                    Upgrade to Pro for unlimited {{ limitKey !== 'ai_docs' ? limitLabel : '' }}{{ limitKey === 'ai_docs' ? '100 AI-processed documents per month' : '' }} and more.
                </DialogDescription>
            </DialogHeader>

            <div class="rounded-xl border border-indigo-100 dark:border-indigo-900/50 bg-indigo-50/50 dark:bg-indigo-900/10 p-4 text-sm text-slate-700 dark:text-slate-300">
                Contact your administrator to upgrade your organization's plan.
            </div>

            <DialogFooter>
                <Button variant="outline" @click="emit('close')">
                    Close
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
