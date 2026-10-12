<script setup lang="ts">
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import aiTemplateRoutes from '@/routes/transformation-library';
import { router, useForm } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import { Settings2, Wand2 } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AiTemplateForm from './AiTemplateForm.vue';

// One sheet over the Transformation Library for viewing, editing and creating a transformation.
// Viewing is the edit form read-only, so Edit only unlocks it in place — the sheet never closes
// and reopens, and the fields don't move. Saving redirects back to the library with the saved
// transformation open for viewing (see AiTemplateController::requestedSheet()). Leaving an edit
// with unsaved changes — the X, Escape, a click outside, Cancel, a link elsewhere in the app, or
// leaving the page — asks first.
const props = defineProps<{
    mode: 'view' | 'edit' | 'create';
    template: AiTemplate | null;
    canEdit: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'edit'): void;
    (e: 'cancelEdit'): void;
}>();

const valuesFrom = (template: AiTemplate | null) => ({
    name: template?.name ?? '',
    description: template?.description ?? '',
    generation_brief: template?.generation_brief ?? '',
    system_prompt: template?.system_prompt ?? '',
    user_prompt: template?.user_prompt ?? '',
    single_output: template?.single_output ?? true,
    output_key: template?.output_key ?? '',
});

const form = useForm(valuesFrom(props.template));

// A save brings the stored transformation back as the new starting point for further edits.
watch(
    () => props.template,
    (template) => {
        form.defaults(valuesFrom(template));
        form.reset();
    },
);

const isConfirmingDiscard = ref(false);
// Where an in-app link was headed when the discard question interrupted it.
const pendingVisitUrl = ref<string | null>(null);
// Set once the changes are saved or knowingly thrown away, so nothing asks again.
const isLeaving = ref(false);

watch(
    () => props.mode,
    () => {
        isLeaving.value = false;
    },
);

const hasUnsavedChanges = () =>
    props.mode !== 'view' && form.isDirty && !isLeaving.value;

const leaveEdit = () => {
    form.reset();
    form.clearErrors();
    if (props.mode === 'edit') {
        emit('cancelEdit');
    } else {
        emit('close');
    }
};

const requestClose = () => {
    if (hasUnsavedChanges()) {
        isConfirmingDiscard.value = true;
        return;
    }
    emit('close');
};

const requestCancel = () => {
    if (hasUnsavedChanges()) {
        isConfirmingDiscard.value = true;
        return;
    }
    leaveEdit();
};

const keepEditing = () => {
    isConfirmingDiscard.value = false;
    pendingVisitUrl.value = null;
};

const discardChanges = () => {
    isLeaving.value = true;
    isConfirmingDiscard.value = false;

    if (pendingVisitUrl.value) {
        router.visit(pendingVisitUrl.value);
        return;
    }
    leaveEdit();
};

const stopGuardingVisits = router.on('before', (event) => {
    if (!hasUnsavedChanges() || event.detail.visit.method !== 'get') {
        return;
    }
    pendingVisitUrl.value = event.detail.visit.url.href;
    isConfirmingDiscard.value = true;
    return false;
});
onBeforeUnmount(stopGuardingVisits);

useEventListener(window, 'beforeunload', (event: BeforeUnloadEvent) => {
    if (hasUnsavedChanges()) {
        event.preventDefault();
    }
});

const submit = () => {
    const template = props.template;
    const route = template
        ? aiTemplateRoutes.update({ aiTemplate: template.id })
        : aiTemplateRoutes.store();

    form[template ? 'put' : 'post'](route.url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            isLeaving.value = true;
            toast.success(
                template ? 'Configuration updated' : 'Transformation created',
            );
        },
        onError: () => {
            toast.error('Validation failed. Please check your inputs.');
        },
    });
};
</script>

<template>
    <div>
        <Sheet open @update:open="(value) => !value && requestClose()">
            <SheetContent
                class="w-full gap-0 overflow-y-auto border-l border-gray-100 bg-white px-6 py-8 shadow-2xl sm:max-w-5xl sm:px-12 dark:border-gray-800 dark:bg-[hsl(222_47%_6%)] dark:text-white"
            >
                <SheetHeader class="mb-8 p-0">
                    <div class="flex h-14 items-center gap-5 pr-8">
                        <div
                            class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-projector-primary-600 text-white shadow-lg shadow-projector-primary-500/30"
                        >
                            <Wand2 class="h-7 w-7" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <SheetTitle
                                class="truncate text-2xl font-black tracking-tight text-gray-900 dark:text-white"
                            >
                                {{ template?.name ?? 'New Transformation' }}
                            </SheetTitle>
                            <SheetDescription
                                class="text-xs font-bold tracking-widest text-gray-400 uppercase"
                            >
                                {{
                                    mode === 'view'
                                        ? 'Transformation'
                                        : mode === 'edit'
                                          ? 'Editing'
                                          : 'Creating'
                                }}
                            </SheetDescription>
                        </div>
                        <Transition name="fade-swap">
                            <Button
                                v-if="mode === 'view' && canEdit"
                                class="shrink-0 border border-gray-200 bg-white px-5 text-gray-900 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                @click="emit('edit')"
                            >
                                <Settings2
                                    class="mr-2 h-4 w-4 text-projector-primary-500"
                                />
                                Edit
                            </Button>
                        </Transition>
                    </div>
                </SheetHeader>

                <AiTemplateForm
                    v-model:name="form.name"
                    v-model:description="form.description"
                    v-model:generation-brief="form.generation_brief"
                    v-model:system-prompt="form.system_prompt"
                    v-model:user-prompt="form.user_prompt"
                    v-model:single-output="form.single_output"
                    v-model:output-key="form.output_key"
                    :readonly="mode === 'view'"
                    :processing="form.processing"
                    :errors="form.errors"
                    :is-editing="!!template"
                    @cancel="requestCancel"
                    @submit="submit"
                />
            </SheetContent>
        </Sheet>

        <ConfirmDeleteModal
            :open="isConfirmingDiscard"
            title="Discard unsaved changes?"
            description="You've made changes to this transformation that haven't been saved. If you leave now, they'll be lost."
            cancel-label="Keep Editing"
            confirm-label="Discard Changes"
            @close="keepEditing"
            @confirm="discardChanges"
        />
    </div>
</template>
