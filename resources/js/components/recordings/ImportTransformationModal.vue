<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { fieldsForListType, IGNORE } from '@/lib/taskListImportFields';
import savedImportTransformationRoutes from '@/routes/import-transformations';
import importTransformationRoutes from '@/routes/projects/import-transformations';
import transformationLibraryRoutes from '@/routes/transformation-library';
import axios from 'axios';
import { Loader2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ImportTransformationPassEditor from './ImportTransformationPassEditor.vue';
import TextExtractionPassEditor from './TextExtractionPassEditor.vue';

// One source — a spreadsheet or a plain-text document — turns into one or more passes, each
// producing its own fully separate Task or Event documents, never a hybrid record. A spreadsheet
// pass resolves a column mapping against real rows (SpreadsheetClassificationService/
// ImportTaskList); a text pass has the AI extract records directly by following a plain-English
// rule instead (TextExtractionService/ExtractTextRecords) — sourceMode picks which the whole
// modal instance is doing; a single upload is always one or the other, never mixed.
const props = withDefaults(
    defineProps<{
        open: boolean;
        projectId: string;
        originalFilename: string | null;
        sourceMode: 'spreadsheet' | 'text';
        headers?: string[];
        rows?: string[][];
        text?: string;
    }>(),
    {
        headers: () => [],
        rows: () => [],
        text: '',
    },
);

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'imported'): void;
}>();

const SOURCE_FRESH = '__fresh__';

const savedTransformations = ref<SavedImportTransformation[]>([]);
const loadingSaved = ref(false);
const selectedSource = ref<string>(SOURCE_FRESH);

// Only offer transformations saved for the same kind of source — a spreadsheet column mapping
// means nothing applied to a text document, and vice versa.
const availableSavedTransformations = computed(() => {
    const wantType =
        props.sourceMode === 'spreadsheet'
            ? 'spreadsheet_import'
            : 'text_import';

    return savedTransformations.value.filter((t) => t.type === wantType);
});

const loadSavedTransformations = async () => {
    loadingSaved.value = true;
    try {
        const response = await axios.get<{
            transformations: SavedImportTransformation[];
        }>(savedImportTransformationRoutes.index.url());
        savedTransformations.value = response.data.transformations;
    } finally {
        loadingSaved.value = false;
    }
};

const noHeaderRow = ref(false);
const effectiveHeaders = computed(() =>
    noHeaderRow.value
        ? props.headers.map((_, index) => `Column ${index + 1}`)
        : props.headers,
);
const effectiveRows = computed(() =>
    noHeaderRow.value ? [props.headers, ...props.rows] : props.rows,
);

// Locally, every pass carries both a sanitized mapping and an extractionRule — only the one
// matching sourceMode is ever read/rendered, but keeping both present (rather than a per-pass
// discriminated shape) is simpler here since sourceMode is fixed for the whole modal instance,
// never mixed within one set of passes.
interface EditablePass {
    list_type: 'task' | 'event';
    mapping: Record<string, string>;
    extractionRule: string;
    rationale?: string | null;
}

const passes = ref<EditablePass[]>([]);
const classifying = ref(false);
const classifyError = ref<string | null>(null);

// Which pass is currently shown — passes.length is almost always 1 or 2 (one record type per
// pass, and a sheet/document practically never gets classified into more than a couple), so one
// full-width editor at a time reads far better than the same two squeezed into this dialog's
// fixed width side by side. Clamped (not just reset) wherever passes.value changes size, so
// removing the pass being viewed lands on a still-valid neighbor instead of an out-of-range page.
const currentPassIndex = ref(0);
const currentPass = computed(() => passes.value[currentPassIndex.value]);

// Which pages have actually been viewed — Import stays disabled (see canImport below) until
// every pass has been, even once all of them are validly mapped, so a multi-pass import can't
// be started without the user having actually looked at each one. Reset (not just re-seeded
// with 0) wherever passes.value is replaced wholesale, since a stale index from a previous set
// of passes means nothing once the passes themselves are different.
const visitedPassIndices = ref<Set<number>>(new Set([0]));
watch(currentPassIndex, (index) => visitedPassIndices.value.add(index));
const allPagesVisited = computed(() =>
    passes.value.every((_, index) => visitedPassIndices.value.has(index)),
);

// Every mapped header must actually exist on THIS sheet — a saved transformation's mapping was
// matched against whatever sheet it was created from, which a new upload's headers may not
// exactly match (renamed/reordered columns). A stale reference just falls back to unmapped
// rather than silently pointing at the wrong column.
const sanitizeMapping = (
    mapping: Record<string, string | null> | undefined,
): Record<string, string> => {
    const result: Record<string, string> = {};
    for (const [key, value] of Object.entries(mapping ?? {})) {
        result[key] =
            value && effectiveHeaders.value.includes(value) ? value : IGNORE;
    }
    return result;
};

const toEditablePass = (pass: ImportTransformationPass): EditablePass => ({
    list_type: pass.list_type,
    mapping: sanitizeMapping(pass.mapping),
    extractionRule: pass.extraction_rule ?? '',
    rationale: pass.rationale,
});

const runClassification = async () => {
    classifying.value = true;
    classifyError.value = null;
    try {
        if (props.sourceMode === 'spreadsheet') {
            const response = await axios.post<{
                passes: ImportTransformationPass[];
            }>(importTransformationRoutes.classify.url(props.projectId), {
                headers: effectiveHeaders.value,
                rows: effectiveRows.value,
            });
            passes.value = response.data.passes.map(toEditablePass);
        } else {
            const response = await axios.post<{
                passes: ImportTransformationPass[];
            }>(importTransformationRoutes.classifyText.url(props.projectId), {
                text: props.text,
            });
            passes.value = response.data.passes.map(toEditablePass);
        }
    } catch {
        classifyError.value =
            props.sourceMode === 'spreadsheet'
                ? "Couldn't analyze this sheet automatically. You can still build passes by hand, or try again."
                : "Couldn't analyze this document automatically. You can still build passes by hand, or try again.";
        passes.value = [];
    } finally {
        classifying.value = false;
    }
};

const applySource = (source: string) => {
    selectedSource.value = source;
    classifyError.value = null;
    currentPassIndex.value = 0;
    visitedPassIndices.value = new Set([0]);

    if (source === SOURCE_FRESH) {
        void runClassification();
        return;
    }

    const saved = availableSavedTransformations.value.find(
        (t) => String(t.id) === source,
    );
    passes.value = (saved?.import_config?.passes ?? []).map(toEditablePass);
};

// Reset to a fresh AI classification every time the modal opens (with whatever source is
// current at that moment) rather than carrying over a previous source's passes — this modal
// instance stays mounted between imports (see ImportTaskListOptions.vue), so nothing else would
// otherwise clear stale state from the last one. `immediate: true` also covers the very first
// time this modal appears, since `open` can already be true the instant it's first rendered.
watch(
    () => [props.open, props.sourceMode, props.headers, props.text] as const,
    async ([isOpen]) => {
        if (!isOpen) return;

        noHeaderRow.value = false;
        selectedSource.value = SOURCE_FRESH;
        passes.value = [];
        classifyError.value = null;
        await loadSavedTransformations();
        applySource(SOURCE_FRESH);
    },
    { immediate: true },
);

// Toggling "no header row" (spreadsheet mode only) swaps effectiveHeaders over to synthetic
// "Column N" labels, so whichever mapping is currently active needs re-sanitizing against those
// instead of the real header text it was originally matched against.
watch(noHeaderRow, () => {
    if (!props.open) return;
    applySource(selectedSource.value);
});

const updateCurrentPassMapping = (mapping: Record<string, string>) => {
    const next = [...passes.value];
    next[currentPassIndex.value] = { ...next[currentPassIndex.value], mapping };
    passes.value = next;
};

const updateCurrentPassExtractionRule = (extractionRule: string) => {
    const next = [...passes.value];
    next[currentPassIndex.value] = {
        ...next[currentPassIndex.value],
        extractionRule,
    };
    passes.value = next;
};

// Only ever called for the pass currently on screen (see :removable and @remove below) — lands
// on the pass now occupying this same page position, or the last remaining one if this was it.
const removeCurrentPass = () => {
    const index = currentPassIndex.value;
    passes.value = passes.value.filter((_, i) => i !== index);
    currentPassIndex.value = Math.min(index, Math.max(0, passes.value.length - 1));
    // Indices shift under the removed pass, so which of the old ones were genuinely visited no
    // longer lines up — simplest to just require the remaining pages be (re)confirmed.
    visitedPassIndices.value = new Set([currentPassIndex.value]);
};

const canImport = computed(() => {
    if (passes.value.length === 0) return false;
    if (!allPagesVisited.value) return false;

    if (props.sourceMode === 'text') {
        return passes.value.every((pass) => pass.extractionRule.trim() !== '');
    }

    return passes.value.every((pass) => {
        const nameField = fieldsForListType(pass.list_type).find(
            (f) => f.key === 'name',
        );
        return !nameField || pass.mapping.name !== IGNORE;
    });
});

const buildPassesPayload = () =>
    passes.value.map((pass) =>
        props.sourceMode === 'spreadsheet'
            ? {
                  list_type: pass.list_type,
                  mapping: Object.fromEntries(
                      fieldsForListType(pass.list_type).map((field) => [
                          field.key,
                          pass.mapping[field.key] === IGNORE
                              ? null
                              : (pass.mapping[field.key] ?? null),
                      ]),
                  ),
              }
            : {
                  list_type: pass.list_type,
                  extraction_rule: pass.extractionRule,
              },
    );

const importing = ref(false);
const runImport = async () => {
    importing.value = true;
    try {
        const usedTransformationId =
            selectedSource.value === SOURCE_FRESH
                ? null
                : Number(selectedSource.value);

        const url =
            props.sourceMode === 'spreadsheet'
                ? importTransformationRoutes.apply.url(props.projectId)
                : importTransformationRoutes.applyText.url(props.projectId);

        const body =
            props.sourceMode === 'spreadsheet'
                ? {
                      original_filename: props.originalFilename,
                      headers: effectiveHeaders.value,
                      rows: effectiveRows.value,
                      ai_template_id: usedTransformationId,
                      passes: buildPassesPayload(),
                  }
                : {
                      original_filename: props.originalFilename,
                      text: props.text,
                      ai_template_id: usedTransformationId,
                      passes: buildPassesPayload(),
                  };

        await axios.post(url, body);

        toast.success(
            `Import started — ${passes.value.length} pass${passes.value.length === 1 ? '' : 'es'} queued.`,
        );
        emit('imported');
        emit('close');
    } catch (err) {
        console.error('Failed to apply import transformation', err);
        toast.error("Couldn't start the import. Please try again.");
    } finally {
        importing.value = false;
    }
};

const savingAs = ref(false);
const saveName = ref('');
const saving = ref(false);
const saveAsTransformation = async () => {
    if (!saveName.value.trim()) return;

    saving.value = true;
    try {
        await axios.post(transformationLibraryRoutes.store.url(), {
            name: saveName.value.trim(),
            type:
                props.sourceMode === 'spreadsheet'
                    ? 'spreadsheet_import'
                    : 'text_import',
            import_config: { passes: buildPassesPayload() },
        });
        toast.success(`Saved "${saveName.value.trim()}" for future imports.`);
        savingAs.value = false;
        saveName.value = '';
        await loadSavedTransformations();
    } catch (err) {
        console.error('Failed to save import transformation', err);
        toast.error("Couldn't save this transformation. Please try again.");
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('close')">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-[720px]">
            <DialogHeader>
                <DialogTitle>Import Data</DialogTitle>
                <DialogDescription v-if="sourceMode === 'spreadsheet'">
                    {{ effectiveRows.length }} row{{
                        effectiveRows.length === 1 ? '' : 's'
                    }}
                    found{{
                        originalFilename ? ` in ${originalFilename}` : ''
                    }}. This sheet can become more than one record type — each
                    pass below creates its own separate Tasks or Events.
                </DialogDescription>
                <DialogDescription v-else>
                    {{ text.length.toLocaleString() }} characters found{{
                        originalFilename ? ` in ${originalFilename}` : ''
                    }}. This document can become more than one record type —
                    each pass below creates its own separate Tasks or Events.
                </DialogDescription>
            </DialogHeader>

            <Label
                v-if="sourceMode === 'spreadsheet'"
                class="flex items-center gap-2 text-xs text-gray-500"
            >
                <Checkbox v-model="noHeaderRow" />
                My file doesn't have a header row
            </Label>

            <div class="grid grid-cols-[160px_1fr] items-center gap-3">
                <Label
                    class="text-[11px] font-black tracking-widest text-gray-500 uppercase"
                >
                    Use
                </Label>
                <Select
                    :model-value="selectedSource"
                    :disabled="loadingSaved"
                    @update:model-value="(v) => applySource(v as string)"
                >
                    <SelectTrigger class="h-9 rounded-md">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="SOURCE_FRESH"
                            >Start fresh (AI-detect)</SelectItem
                        >
                        <SelectItem
                            v-for="t in availableSavedTransformations"
                            :key="t.id"
                            :value="String(t.id)"
                        >
                            {{ t.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div
                v-if="classifying"
                class="flex items-center justify-center gap-2 py-10 text-sm text-gray-500"
            >
                <Loader2 class="h-4 w-4 animate-spin" />
                Analyzing your
                {{ sourceMode === 'spreadsheet' ? 'sheet' : 'document' }}…
            </div>

            <p v-else-if="classifyError" class="text-sm text-red-500">
                {{ classifyError }}
            </p>

            <div
                v-else-if="passes.length === 0"
                class="py-6 text-center text-sm text-gray-500"
            >
                No record types detected in this
                {{ sourceMode === 'spreadsheet' ? 'sheet' : 'document' }}.
            </div>

            <div v-else class="space-y-3">
                <div v-if="passes.length > 1" class="flex items-center gap-3">
                    <span
                        v-for="(pass, index) in passes"
                        :key="index"
                        :class="[
                            'text-sm',
                            index === currentPassIndex
                                ? 'font-black text-slate-900 dark:text-slate-100'
                                : 'text-gray-300 dark:text-gray-700',
                        ]"
                    >
                        {{ pass.list_type === 'task' ? 'Task' : 'Event' }}
                    </span>
                </div>

                <ImportTransformationPassEditor
                    v-if="sourceMode === 'spreadsheet' && currentPass"
                    :key="currentPassIndex"
                    :list-type="currentPass.list_type"
                    :headers="effectiveHeaders"
                    :rows="effectiveRows"
                    :mapping="currentPass.mapping"
                    :rationale="currentPass.rationale"
                    :removable="passes.length > 1"
                    @update:mapping="updateCurrentPassMapping"
                    @remove="removeCurrentPass"
                />
                <TextExtractionPassEditor
                    v-else-if="currentPass"
                    :key="currentPassIndex"
                    :list-type="currentPass.list_type"
                    :extraction-rule="currentPass.extractionRule"
                    :rationale="currentPass.rationale"
                    :removable="passes.length > 1"
                    @update:extraction-rule="updateCurrentPassExtractionRule"
                    @remove="removeCurrentPass"
                />
            </div>

            <div
                class="rounded-lg border border-dashed border-gray-200 p-3 dark:border-gray-700"
            >
                <div v-if="!savingAs">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="passes.length === 0"
                        @click="savingAs = true"
                    >
                        Save as Transformation…
                    </Button>
                    <p class="mt-1 text-xs text-gray-400">
                        Save this
                        {{
                            sourceMode === 'spreadsheet'
                                ? 'mapping'
                                : 'rule set'
                        }}
                        so your organization can reuse it on future
                        {{
                            sourceMode === 'spreadsheet'
                                ? 'sheets'
                                : 'documents'
                        }}
                        shaped like this one.
                    </p>
                </div>
                <div v-else class="flex items-center gap-2">
                    <Input
                        v-model="saveName"
                        placeholder="Name this transformation"
                        class="h-9"
                        @keydown.enter="saveAsTransformation"
                    />
                    <Button
                        size="sm"
                        :disabled="!saveName.trim() || saving"
                        @click="saveAsTransformation"
                    >
                        {{ saving ? 'Saving…' : 'Save' }}
                    </Button>
                    <Button variant="ghost" size="sm" @click="savingAs = false">
                        Cancel
                    </Button>
                </div>
            </div>

            <DialogFooter class="gap-2 sm:gap-4">
                <Button variant="outline" @click="emit('close')">
                    Cancel
                </Button>
                <Button
                    v-if="currentPassIndex > 0"
                    variant="outline"
                    @click="currentPassIndex -= 1"
                >
                    Previous
                </Button>
                <Button
                    v-if="currentPassIndex < passes.length - 1"
                    @click="currentPassIndex += 1"
                >
                    Next
                </Button>
                <Button v-else :disabled="!canImport || importing" @click="runImport">
                    {{ importing ? 'Importing…' : 'Import' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
