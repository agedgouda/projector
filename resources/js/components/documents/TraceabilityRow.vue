<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import {
    ChevronRight, Edit2, Trash2, RotateCw, FileText,
    CheckSquare, X, User, Clock, FileType, Search
} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { toast } from 'vue-sonner'


import InlineDocumentForm from './InlineDocumentForm.vue';
import TraceabilityRow from './TraceabilityRow.vue';

const props = defineProps<{
    item: any;
    level: number;
    expandedRootIds: Set<string | number>;
    getDocLabel: (type: string) => string;
    getLeadUser: (item: any) => any;
    requirementStatus?: any[];
    users?: any[];
    form?: any;
    activeEditingId: string | number | null;
}>();

const emit = defineEmits(['toggleRoot', 'handleReprocess', 'onDeleteRequested', 'prepareEdit', 'submit']);
const localDisplayContent = ref(props.item.content);
const localDisplayUpdateAt = ref(props.item.updated_at);

watch(() => props.item.content, (newVal) => {
    localDisplayContent.value = newVal;
});
watch(() => props.item.updated_at, (newVal) => {
    localDisplayUpdateAt.value = newVal;
});

// Local state to track if the Preview is open for this specific row
const isPreviewOpen = ref(false);

const isLevel0 = props.level === 0;
const isLevel1 = props.level === 1;

// State: Is this specific row being edited?
const isEditing = computed(() => props.activeEditingId === props.item.id);

// State: Is the tree expanded (showing children)?
const isTreeExpanded = computed(() => {
    return props.expandedRootIds instanceof Set && props.expandedRootIds.has(props.item.id);
});

// SMART TOGGLE: Handles both Preview and Canceling Edit
const handleMainAction = () => {
    if (isEditing.value) {
        // If editing, the button acts as a Cancel (X)
        emit('prepareEdit', { id: null });
    } else {
        // Otherwise it toggles the Preview
        isPreviewOpen.value = !isPreviewOpen.value;
    }
};

const handleEditClick = () => {
    if (!isEditing.value) {
        emit('prepareEdit', props.item);
        // Force preview open so the form is visible
        isPreviewOpen.value = true;
    } else {
        emit('prepareEdit', { id: null });
    }
};

const handleFormSubmit = () => {
    // 1. Capture IMMEDIATELY for optimistic UI
    const optimisticContent = props.form.content;

    // 2. Update local display refs
    localDisplayContent.value = optimisticContent;
    localDisplayUpdateAt.value = new Date().toISOString();

    // 3. Emit the single submit event
    emit('submit', () => {
        // This callback runs after the server succeeds
        emit('prepareEdit', { id: null });
        isPreviewOpen.value = true;

        // Trigger Sonner toast
        toast.success('Changes saved', {
            description: `${props.item.name || 'Document'} has been updated successfully.`,
            duration: 3000,
        });
    });
};
</script>

<template>
    <div class="flex flex-col">
        <div
            class="grid grid-cols-12 items-center px-10 transition-colors"
            :class="[
                isLevel0 ? 'py-5 hover:bg-slate-50/50' : 'relative',
                isEditing ? 'bg-indigo-50/30' : '',
                isPreviewOpen && !isEditing ? 'bg-blue-50/10' : ''
            ]"
        >
            <div
                class="col-span-8 flex items-center gap-3 relative"
                :class="[isLevel0 ? '' : (isLevel1 ? 'pl-12 py-4' : 'pl-24 py-2')]"
            >
                <template v-if="!isLevel0">
                    <div class="absolute left-6 top-0 bottom-0 w-px bg-slate-200"></div>
                    <div v-if="level > 1" class="absolute left-[24px] top-0 bottom-0 w-px bg-slate-200"></div>
                    <div class="absolute top-1/2 h-px bg-slate-200" :class="[isLevel1 ? 'left-6 w-5' : 'left-[24px] w-4']"></div>
                </template>

                <div
                    class="w-6 flex items-center justify-center cursor-pointer hover:bg-slate-100 rounded-md transition-colors"
                    @click="emit('toggleRoot', item.id)"
                >
                    <ChevronRight
                        v-if="item.children?.length"
                        :class="[
                            'text-slate-400 transition-transform duration-300 ease-in-out',
                            isLevel0 ? 'h-4 w-4' : 'h-3.5 w-3.5',
                            { 'rotate-90': isTreeExpanded }
                        ]"
                    />
                </div>

                <div
                    class="flex-1 flex items-center transition-all duration-200"
                    :class="[
                        isLevel0 ? '' : 'bg-white border border-slate-200 p-3 rounded-2xl shadow-sm',
                        isEditing ? 'ring-2 ring-indigo-500 border-transparent' : '',
                    ]"
                >
                    <div :class="['p-2 rounded-xl mr-3', item.is_task ? 'bg-emerald-50 text-emerald-600' : 'bg-blue-50 text-blue-600']">
                        <CheckSquare v-if="item.is_task" class="h-5 w-5" />
                        <FileText v-else :class="isLevel0 ? 'h-5 w-5' : 'h-4 w-4'" />
                    </div>

                    <div class="flex-1 overflow-hidden text-left">
                        <div class="font-bold text-slate-900 truncate" :class="isLevel0 ? 'text-sm' : 'text-[11px] font-black uppercase'">{{ item.name }}</div>
                        <div v-if="item.processingError" class="text-[11px] text-red-600 font-black mt-1 p-1 bg-red-50 rounded border border-red-100 inline-block uppercase tracking-tighter">{{ item.processingError }}</div>
                        <div v-else class="text-[9px] text-slate-400 font-bold tracking-widest uppercase mt-0.5">{{ getDocLabel(item.type) }}</div>
                    </div>
                </div>
            </div>

            <div class="col-span-2 flex justify-center border-x border-slate-50">
                <TooltipProvider v-if="getLeadUser(item)">
                    <Tooltip>
                        <TooltipTrigger>
                            <div class="rounded-full border-2 border-white shadow-sm bg-slate-100 flex items-center justify-center overflow-hidden" :class="isLevel0 ? 'h-9 w-9' : 'h-7 w-7'">
                                <img v-if="getLeadUser(item).avatar" :src="getLeadUser(item).avatar" class="object-cover h-full w-full" />
                                <div v-else class="text-indigo-600 font-bold text-[10px]">{{ getLeadUser(item).initials }}</div>
                            </div>
                        </TooltipTrigger>
                        <TooltipContent><p class="text-xs font-bold">{{ getLeadUser(item).first_name }}</p></TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>

            <div class="col-span-2 flex justify-center gap-1">
                <Button
                    variant="ghost"
                    size="icon"
                    @click.stop="handleMainAction"
                    :class="[
                        isLevel0 ? 'h-8 w-8' : 'h-7 w-7',
                        isEditing ? 'text-amber-600 bg-amber-50 hover:bg-amber-100' :
                        isPreviewOpen ? 'text-blue-600 bg-blue-50' : 'text-slate-400 hover:text-blue-600'
                    ]"
                >
                    <X v-if="isEditing" class="h-3.5 w-3.5" />
                    <Search v-else class="h-3.5 w-3.5" />
                </Button>

                <template v-if="!isEditing">
                    <Button v-if="!item.is_task" variant="ghost" size="icon" @click.stop="emit('handleReprocess', item.id)" class="text-slate-400 hover:text-blue-600" :class="isLevel0 ? 'h-8 w-8' : 'h-7 w-7'"><RotateCw class="h-3.5 w-3.5" /></Button>
                    <Button variant="ghost" size="icon" @click.stop="emit('onDeleteRequested', item)" class="text-slate-400 hover:text-red-600" :class="isLevel0 ? 'h-8 w-8' : 'h-7 w-7'"><Trash2 class="h-3.5 w-3.5" /></Button>
                </template>
            </div>
        </div>

        <transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0">
            <div v-if="isPreviewOpen || isEditing" class="relative px-10 pb-4">
                <div class="absolute top-0 bottom-4 w-px bg-slate-200" :class="[isLevel0 ? 'left-[46px]' : (isLevel1 ? 'left-[94px]' : 'left-[142px]')]"></div>

                <div class="ml-auto" :class="[isLevel0 ? 'w-[calc(100%-60px)]' : 'w-[calc(100%-120px)]']">

                    <div v-if="isEditing" class="mt-2 mb-4">
                        <InlineDocumentForm
                            mode="edit"
                            :form="form"
                            :requirement-status="requirementStatus"
                            :users="users"
                            @submit="handleFormSubmit"
                            @cancel="emit('prepareEdit', { id: null })"
                        />
                    </div>

                    <div v-show="!isEditing" class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm mb-4">
                        <div class="flex items-center gap-6 mb-4 pb-4 border-b border-slate-100">
                            <div class="flex items-center gap-2 text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                                <FileType class="h-3 w-3" /> {{ getDocLabel(item.type) }}
                            </div>
                            <div v-if="getLeadUser(item)" class="flex items-center gap-2 text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                                <User class="h-3 w-3" /> {{ getLeadUser(item).first_name }} {{ getLeadUser(item).last_name }}
                            </div>
                            <div v-if="item.updated_at" class="flex items-center gap-2 text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                                <Clock class="h-3 w-3" /> {{ new Date(item.updated_at).toLocaleDateString() }}
                            </div>
                        </div>

                        <div class="text-sm text-slate-600 leading-relaxed prose prose-sm prose-slate max-w-none">
                            <div
                                v-if="localDisplayContent"
                                v-html="localDisplayContent"
                                :key="localDisplayUpdateAt"
                            ></div>
                            <div v-else class="italic text-slate-400">No content provided for this document.</div>
                        </div>

                        <div class="mt-6 flex justify-end">
                            <Button variant="outline" size="sm" @click="handleEditClick" class="text-xs font-bold rounded-xl px-4 border-slate-200 hover:text-indigo-600 hover:bg-slate-50">
                                <Edit2 class="h-3 w-3 mr-2" /> Edit Details
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </transition>

        <transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="transform opacity-0 -translate-y-2"
            enter-to-class="transform opacity-100 translate-y-0"
        >
            <div v-if="isTreeExpanded && item.children?.length" class="relative" :class="isLevel0 ? 'bg-slate-50/30' : ''">
                <TraceabilityRow
                    v-for="child in item.children"
                    :key="child.id"
                    :item="child"
                    :level="level + 1"
                    :expanded-root-ids="expandedRootIds"
                    :active-editing-id="activeEditingId"
                    :get-doc-label="getDocLabel"
                    :get-lead-user="getLeadUser"
                    :requirement-status="requirementStatus"
                    :users="users"
                    :form="form"
                    @toggle-root="(id) => emit('toggleRoot', id)"
                    @handle-reprocess="(id) => emit('handleReprocess', id)"
                    @on-delete-requested="(i) => emit('onDeleteRequested', i)"
                    @prepare-edit="(i) => emit('prepareEdit', i)"
                    @submit="(cb) => emit('submit', cb)"
                />
            </div>
        </transition>
    </div>
</template>

