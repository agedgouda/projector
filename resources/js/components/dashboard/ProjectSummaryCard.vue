<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronDown, ExternalLink } from 'lucide-vue-next';
import PieChart from '@/components/dashboard/PieChart.vue';

// A card starts collapsed to just its "All" pie — the subproject breakdown and both
// deliverable lists were getting cut off once several project cards sat side by side in the
// grid. Expanding reveals the rest in place (own local `expanded` state, same pattern as
// KanbanRowCollapsible.vue's dashboard-redesign row) rather than sending the user to the full
// project page just to see it.
defineProps<{
    label: string;
    viewProjectUrl: string | null;
    allTitle?: string;
    allSegments: { label: string; count: number; color: string }[];
    memberBreakdowns: { title: string; segments: { label: string; count: number; color: string }[] }[];
    upcoming: { id: string | number; name: string; dueLabel: string }[];
    yours: { id: string | number; name: string; dueLabel: string }[];
}>();

const expanded = ref(false);
</script>

<template>
    <!-- Spans 2 grid columns once expanded — the parent grid (Dashboard2/Index.vue) is 3-up on
         wide screens, so this doubles the card's width right when it needs the room, without
         permanently widening every other still-collapsed card. -->
    <section
        class="space-y-3 rounded-2xl border border-gray-200 p-4 dark:border-gray-800"
        :class="{ 'md:col-span-2': expanded }"
    >
        <div class="flex items-center gap-3">
            <h2 class="flex-1 truncate text-sm font-black text-gray-900 dark:text-gray-100">{{ label }}</h2>
            <Link
                v-if="viewProjectUrl"
                :href="viewProjectUrl"
                class="flex shrink-0 items-center gap-1.5 text-[10px] font-black tracking-widest text-projector-primary-600 uppercase transition-colors hover:text-projector-primary-800"
            >
                <ExternalLink class="h-3 w-3" />
                View Project
            </Link>
        </div>

        <PieChart :title="allTitle" :segments="allSegments" />

        <button
            v-if="memberBreakdowns.length > 0 || upcoming.length > 0 || yours.length > 0"
            type="button"
            class="flex items-center gap-1 text-[10px] font-black tracking-widest text-gray-400 uppercase transition-colors hover:text-gray-600 dark:hover:text-gray-300"
            @click="expanded = !expanded"
        >
            <ChevronDown class="h-3 w-3 transition-transform duration-200" :class="{ 'rotate-180': expanded }" />
            {{ expanded ? 'Show Less' : 'Show More' }}
        </button>

        <div v-if="expanded" class="space-y-4">
            <div v-if="memberBreakdowns.length > 0" class="space-y-2">
                <PieChart v-for="member in memberBreakdowns" :key="member.title" :title="member.title" :segments="member.segments" />
            </div>

            <div>
                <h3 class="mb-1 text-[10px] font-semibold tracking-widest text-gray-700 uppercase dark:text-gray-300">Next Deliverables Due</h3>
                <div v-if="upcoming.length === 0" class="text-sm text-gray-400">Nothing due yet.</div>
                <ul v-else>
                    <li
                        v-for="(item, index) in upcoming"
                        :key="item.id"
                        class="flex items-center gap-3 rounded-md px-2 py-1 text-sm"
                        :class="index % 2 === 1 ? 'bg-projector-primary-100/70 dark:bg-projector-primary-950/25' : ''"
                    >
                        <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100">{{ item.name }}</span>
                        <span class="w-14 shrink-0 text-right text-gray-500">{{ item.dueLabel }}</span>
                    </li>
                </ul>
            </div>

            <div v-if="yours.length > 0">
                <h3 class="mb-1 text-[10px] font-semibold tracking-widest text-gray-700 uppercase dark:text-gray-300">Your Deliverables</h3>
                <ul>
                    <li
                        v-for="(item, index) in yours"
                        :key="item.id"
                        class="flex items-center gap-3 rounded-md px-2 py-1 text-sm"
                        :class="index % 2 === 1 ? 'bg-projector-primary-100/70 dark:bg-projector-primary-950/25' : ''"
                    >
                        <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100">{{ item.name }}</span>
                        <span class="w-14 shrink-0 text-right text-gray-500">{{ item.dueLabel }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
