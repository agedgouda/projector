<script setup lang="ts">
import DOMPurify from 'dompurify';
import { computed } from 'vue';

// The contents of every row-preview popover — task rows (TaskRowContent.vue) and non-task
// rows (TraceabilityRow.vue's own branch) both render this, so what a preview looks like only
// has to change in one place. Each caller still owns its own <Popover>/<PopoverAnchor> — that
// part is necessarily row-specific (it needs the row's own DOM element to size the popover to
// the row's width) — but everything about what's actually shown lives here.
//
// No "Go to" button here — the title and eye icon that open this preview (on hover) already
// navigate to the document on click, so a second navigation control inside the popover itself
// would just be a redundant target.
const props = defineProps<{
    name: string;
    content: string | null | undefined;
}>();

// Same sanitize-and-render approach DocumentContent.vue uses for the full document body —
// showing the full formatted content (not a stripped/truncated plain-text summary) means the
// preview needs the same HTML rendering, not just text.
const sanitizedContent = computed(
    () =>
        DOMPurify.sanitize(props.content ?? '') ||
        '<p>No description provided.</p>',
);
</script>

<template>
    <div class="space-y-3">
        <p
            class="text-sm leading-snug font-bold text-slate-900 dark:text-white"
        >
            {{ name }}
        </p>
        <div
            class="preview-content max-h-80 overflow-y-auto text-xs leading-relaxed text-slate-600 dark:text-slate-400"
            v-html="sanitizedContent"
        ></div>
    </div>
</template>

<style scoped>
/* Scaled-down mirror of DocumentContent.vue's own :deep() formatting rules, for the same
   AI-generated HTML content shown compactly inside a popover. */
.preview-content :deep(ol) {
    list-style-type: decimal;
    padding-left: 1.25rem;
    margin: 0.5rem 0;
}
.preview-content :deep(ul) {
    list-style-type: disc;
    padding-left: 1.25rem;
}
.preview-content :deep(li) {
    margin-bottom: 0.25rem;
}
.preview-content :deep(p) {
    margin: 0.5rem 0;
}
.preview-content :deep(h1),
.preview-content :deep(h2),
.preview-content :deep(h3),
.preview-content :deep(h4) {
    font-weight: 800;
    margin: 0.75rem 0 0.375rem;
    color: rgb(15 23 42);
}
:global(html.dark) .preview-content :deep(h1),
:global(html.dark) .preview-content :deep(h2),
:global(html.dark) .preview-content :deep(h3),
:global(html.dark) .preview-content :deep(h4) {
    color: rgb(241 245 249);
}
.preview-content :deep(strong) {
    font-weight: 700;
}
.preview-content :deep(table) {
    width: 100%;
    border-collapse: collapse;
    margin: 0.5rem 0;
}
.preview-content :deep(th),
.preview-content :deep(td) {
    border: 1px solid rgb(226 232 240);
    padding: 0.25rem 0.5rem;
    text-align: left;
}
:global(html.dark) .preview-content :deep(th),
:global(html.dark) .preview-content :deep(td) {
    border-color: rgb(51 65 85);
}
</style>
