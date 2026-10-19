<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { exit } from '@/routes/demo';
import { Link, usePage } from '@inertiajs/vue3';
import { Sparkles } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage<AppPageProps>();

const INTAKE_KEY = 'intake';
const MEETING_NOTES_KEY = 'action_items';

// What to try next, based on where the visitor is — the demo is the real app, so these point
// at its real controls rather than anything demo-specific.
const hint = computed((): string => {
    if (page.component === 'Documents/Show') {
        const type = (page.props.item as { type?: string } | undefined)?.type;

        if (type === INTAKE_KEY) {
            return 'This is the original meeting transcript. AI turned it into Meeting Notes automatically. Click View Generated Meeting Notes to see them.';
        }

        if (type === MEETING_NOTES_KEY) {
            return 'AI wrote these Meeting Notes from the meeting transcript (View Source shows the original). Now click Transform under Properties, choose Create Tasks and click Run.';
        }

        return 'Everything here traces back to the meeting it came from. Use View Source to follow it back, or open the Tasks and Calendar tabs on the project.';
    }

    if (page.component === 'Projects/Show') {
        const tab = new URLSearchParams(page.url.split('?')[1] ?? '').get(
            'tab',
        );

        if (tab === 'tasks') {
            return 'Tasks created from meetings land on this board. Drag them between columns, or open the Calendar tab to see their due dates.';
        }

        if (tab === 'calendar') {
            return 'Every task with a due date shows up on the calendar.';
        }

        return 'AI turns each meeting transcript into Meeting Notes as soon as it arrives. Open the Meeting Notes under Documentation to turn them into tasks.';
    }

    return 'Open one of the projects under Favorites to see a meeting become Meeting Notes and tasks.';
});
</script>

<template>
    <div
        v-if="page.props.demo"
        class="sticky top-0 z-50 flex flex-wrap items-center justify-center gap-x-4 gap-y-2 bg-projector-primary-600 px-4 py-2 text-[13px] text-white"
    >
        <span
            class="flex items-center gap-1.5 text-[11px] font-black tracking-widest uppercase"
        >
            <Sparkles class="h-3.5 w-3.5" />
            Demo
        </span>
        <span class="max-w-3xl text-center">{{ hint }}</span>
        <Button
            as-child
            size="sm"
            variant="secondary"
            class="h-6 bg-white px-2 text-[10px] font-black tracking-widest text-projector-primary-700 uppercase hover:bg-projector-primary-50"
        >
            <Link :href="exit()" as="button">Start free trial</Link>
        </Button>
    </div>
</template>
