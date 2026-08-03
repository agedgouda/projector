<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useProjectTypeForm } from '@/composables/useProjectTypeForm';
import { usePage } from '@inertiajs/vue3';
import { ArrowRight, Plus, X } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    editData: any | null;
    template?: any;
    iconLibrary: { name: string; component: any }[];
    aiTemplates: { id: string; name: string }[];
    organizations: { id: string; name: string }[];
}>();

const emit = defineEmits(['success', 'cancel']);

const page = usePage<AppPageProps>();
const isSuperAdmin = computed(
    () => page.props.auth.user?.roles?.includes('super-admin') ?? false,
);

// Use the new composable
const { form, submit, addSchemaItem, addWorkflowStep, suggestKey } =
    useProjectTypeForm(props.editData, () => emit('success'), props.template);
</script>

<template>
    <form @submit.prevent="submit" class="space-y-10 px-10">
        <div class="max-w-2xl space-y-10">
            <div v-if="isSuperAdmin" class="space-y-4">
                <Label
                    class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                    >Organization</Label
                >
                <select
                    v-model="form.organization_id"
                    class="h-12 w-full rounded-xl border border-gray-200 bg-white px-4 text-sm font-medium text-gray-900 transition-all outline-none focus:ring-4 focus:ring-projector-primary-500/5 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-100"
                >
                    <option value="">
                        — No Organization (super-admin only) —
                    </option>
                    <option
                        v-for="org in organizations"
                        :key="org.id"
                        :value="org.id"
                    >
                        {{ org.name }}
                    </option>
                </select>
            </div>

            <div v-if="isSuperAdmin" class="flex items-center gap-3">
                <input
                    id="is_template"
                    type="checkbox"
                    v-model="form.is_template"
                    class="h-4 w-4 cursor-pointer rounded border-gray-300 text-projector-primary-600 focus:ring-projector-primary-500 dark:border-gray-700 dark:bg-gray-900"
                />
                <Label
                    for="is_template"
                    class="cursor-pointer text-sm font-medium text-gray-700 dark:text-gray-300"
                >
                    Use as default template for new protocols
                </Label>
            </div>

            <div class="space-y-4">
                <Label
                    class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                    >Name</Label
                >
                <Input
                    v-model="form.name"
                    placeholder="e.g. Enterprise SaaS Workflow"
                    class="h-12 rounded-xl border-gray-200 bg-white text-lg font-bold transition-all focus:ring-4 focus:ring-projector-primary-500/5 dark:border-gray-800 dark:bg-gray-950"
                />
            </div>

            <div class="space-y-4">
                <Label
                    class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                    >Icon</Label
                >
                <div
                    class="grid w-full grid-cols-5 gap-2 rounded-xl border border-gray-200 bg-white p-3 sm:grid-cols-8 lg:grid-cols-10 dark:border-gray-800 dark:bg-gray-950"
                >
                    <button
                        v-for="icon in iconLibrary"
                        :key="icon.name"
                        type="button"
                        @click="form.icon = icon.name"
                        :class="[
                            'flex items-center justify-center rounded-lg p-3 transition-all',
                            form.icon === icon.name
                                ? 'bg-projector-primary-600 text-white shadow-md'
                                : 'text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800',
                        ]"
                    >
                        <component :is="icon.component" class="h-4 w-4" />
                    </button>
                </div>
            </div>
        </div>

        <div class="flex max-w-2xl items-center justify-between px-1">
            <h3
                class="text-[10px] font-black tracking-widest text-gray-400 uppercase"
            >
                Document Definitions
            </h3>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                @click="addSchemaItem"
                class="h-6 rounded px-2 text-[9px] font-black text-projector-primary-600 uppercase hover:bg-projector-primary-50"
            >
                <Plus class="mr-1 h-3 w-3" /> Add Definition
            </Button>
        </div>

        <div
            class="overflow-hidden rounded-xl border border-gray-100 bg-white dark:border-gray-800 dark:bg-gray-950"
        >
            <div
                class="flex items-center border-b border-gray-100 bg-gray-50/50 px-4 py-2 dark:border-gray-800 dark:bg-gray-900/50"
            >
                <div
                    class="flex w-12 shrink-0 justify-center text-[9px] font-black text-gray-400 uppercase"
                >
                    Task
                </div>
                <div
                    class="w-72 shrink-0 border-l border-gray-200/50 px-4 text-[9px] font-black text-gray-400 uppercase dark:border-gray-700/50"
                >
                    Label
                </div>
                <div
                    class="flex-1 border-l border-gray-200/50 px-6 text-[9px] font-black text-gray-400 uppercase dark:border-gray-700/50"
                >
                    System Key
                </div>
                <div class="w-8 shrink-0"></div>
            </div>

            <div
                v-for="(doc, index) in form.document_schema"
                :key="index"
                class="flex items-center border-b border-gray-100 px-4 py-1 transition-colors last:border-0 hover:bg-gray-50/50 dark:border-gray-800 dark:hover:bg-gray-900/50"
            >
                <div class="flex w-12 shrink-0 justify-center">
                    <input
                        type="checkbox"
                        v-model="doc.is_task"
                        class="h-3.5 w-3.5 cursor-pointer rounded border-gray-300 text-projector-primary-600 focus:ring-projector-primary-500 dark:border-gray-700 dark:bg-gray-900"
                    />
                </div>
                <div
                    class="w-72 shrink-0 border-l border-gray-100 px-4 dark:border-gray-800"
                >
                    <Input
                        v-model="doc.label"
                        @input="suggestKey(index)"
                        placeholder="e.g. Technical Specification"
                        class="h-9 border-none bg-transparent px-0 text-sm text-gray-900 shadow-none focus-visible:ring-0 dark:text-gray-100"
                    />
                </div>
                <div
                    class="flex flex-1 items-center border-l border-gray-100 px-1 dark:border-gray-800"
                >
                    <Input
                        v-model="doc.key"
                        class="h-9 border-none bg-transparent pl-5 font-mono text-[11px] text-gray-900 shadow-none focus-visible:ring-0 dark:text-gray-100"
                        :disabled="doc.key === 'intake'"
                    />
                </div>
                <div class="shrink-0">
                    <Button
                        v-if="doc.key !== 'intake'"
                        type="button"
                        variant="ghost"
                        size="icon"
                        @click="form.document_schema.splice(index, 1)"
                        class="h-8 w-8 text-gray-300 transition-colors hover:text-red-500"
                    >
                        <X class="h-4 w-4" />
                    </Button>
                    <div v-else class="h-8 w-8"></div>
                </div>
            </div>
        </div>

        <div class="flex max-w-2xl items-center justify-between px-1">
            <h3
                class="text-[10px] font-black tracking-widest text-gray-400 uppercase"
            >
                AI Pipelines
            </h3>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                @click="addWorkflowStep"
                class="h-6 rounded px-2 text-[9px] font-black text-projector-primary-600 uppercase hover:bg-projector-primary-50"
            >
                <Plus class="mr-1 h-3 w-3" /> Add Step
            </Button>
        </div>

        <div
            class="overflow-hidden rounded-xl border border-gray-100 bg-white dark:border-gray-800 dark:bg-gray-950"
        >
            <div
                v-for="(step, index) in form.workflow"
                :key="index"
                class="flex items-center gap-4 border-b border-gray-100 px-4 py-1.5 transition-colors last:border-0 hover:bg-gray-50/50 dark:border-gray-800 dark:hover:bg-gray-900/50"
            >
                <div class="flex w-48 shrink-0 items-center gap-2">
                    <Label
                        class="w-8 shrink-0 text-[9px] font-black text-gray-400 uppercase"
                        >From</Label
                    >
                    <select
                        v-model="step.from_key"
                        class="h-8 w-full cursor-pointer appearance-none border-none bg-transparent text-[11px] text-gray-900 shadow-none outline-none dark:text-gray-100"
                    >
                        <option value="" disabled>Select</option>
                        <option
                            v-for="s in form.document_schema"
                            :key="s.key"
                            :value="s.key"
                        >
                            {{ s.label }}
                        </option>
                    </select>
                </div>
                <ArrowRight
                    class="h-3 w-3 shrink-0 text-projector-primary-400"
                />
                <div class="flex w-48 shrink-0 items-center gap-2">
                    <Label
                        class="w-6 shrink-0 text-[9px] font-black text-gray-400 uppercase"
                        >To</Label
                    >
                    <select
                        v-model="step.to_key"
                        class="h-8 w-full cursor-pointer appearance-none border-none bg-transparent text-[11px] text-gray-900 shadow-none outline-none dark:text-gray-100"
                    >
                        <option value="" disabled>Select</option>
                        <option
                            v-for="s in form.document_schema"
                            :key="s.key"
                            :value="s.key"
                        >
                            {{ s.label }}
                        </option>
                    </select>
                </div>
                <div
                    class="flex flex-1 items-center gap-3 border-l border-gray-100 pl-4 dark:border-gray-800"
                >
                    <select
                        v-model="step.ai_template_id"
                        class="h-8 w-full cursor-pointer appearance-none border-none bg-transparent text-[11px] text-gray-600 shadow-none outline-none dark:text-gray-400"
                    >
                        <option :value="null">Manual Processing</option>
                        <option
                            v-for="temp in aiTemplates"
                            :key="temp.id"
                            :value="temp.id"
                        >
                            {{ temp.name }}
                        </option>
                    </select>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    @click="form.workflow.splice(index, 1)"
                    class="h-8 w-8 shrink-0 text-gray-300 transition-colors hover:text-red-500"
                >
                    <X class="h-4 w-4" />
                </Button>
            </div>
        </div>

        <div
            class="flex max-w-2xl items-center justify-between border-t border-gray-100 pt-6 dark:border-gray-800"
        >
            <div class="flex-1"></div>

            <div class="flex items-center gap-2">
                <Button
                    type="button"
                    @click="emit('cancel')"
                    class="h-9 rounded-lg border border-projector-primary-600 bg-white px-6 text-[9px] font-black tracking-widest text-projector-primary-600 uppercase hover:bg-projector-primary-50 dark:border-projector-primary-400 dark:bg-transparent dark:text-projector-primary-400 dark:hover:bg-projector-primary-950/30"
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    :disabled="form.processing"
                    class="h-9 px-6 text-[9px]"
                >
                    {{ form.processing ? 'Saving...' : 'Save' }}
                </Button>
            </div>
        </div>
    </form>
</template>
