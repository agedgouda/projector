<script setup lang="ts">
import { computed } from 'vue';
import ProjectFolio from '@/components/projects/ProjectFolio.vue';

const props = defineProps<{
    // A single client's projects (top-level + their sub-projects) — already whatever
    // subset the caller wants shown (e.g. post-search-filter). This component owns the
    // parent-then-indented-children ordering and the per-group row striping (restarting
    // at 0 here), matching OrgUserTable.vue's alternating rule — the one thing that had
    // drifted out of sync between the Project Portfolio and Clients-tab project lists.
    projects: Project[];
    redirectTo?: string;
}>();

const ordered = computed(() => {
    const ids = new Set(props.projects.map((p) => p.id));
    const childrenByParentId = new Map<string, Project[]>();

    props.projects.forEach((project) => {
        if (!project.parent_id || !ids.has(project.parent_id)) return;
        const siblings = childrenByParentId.get(project.parent_id) ?? [];
        siblings.push(project);
        childrenByParentId.set(project.parent_id, siblings);
    });

    // A child whose parent isn't in this (filtered) set falls back to rendering at the
    // top level, unindented, so it's never silently dropped from the list.
    const topLevel = props.projects
        .filter((project) => !project.parent_id || !ids.has(project.parent_id))
        .slice()
        .sort((a, b) => a.name.localeCompare(b.name));

    const flattened: { project: Project; isSubProject: boolean }[] = [];
    topLevel.forEach((project) => {
        flattened.push({ project, isSubProject: false });
        (childrenByParentId.get(project.id) ?? [])
            .slice()
            .sort((a, b) => a.name.localeCompare(b.name))
            .forEach((child) => flattened.push({ project: child, isSubProject: true }));
    });

    return flattened.map((entry, rowIndex) => ({ ...entry, rowIndex }));
});
</script>

<template>
    <div v-if="ordered.length === 0" class="py-4 text-slate-400 text-[10px] font-black uppercase tracking-widest italic">
        No projects yet.
    </div>

    <ProjectFolio
        v-for="entry in ordered"
        :key="entry.project.id"
        :project="entry.project"
        :is-sub-project="entry.isSubProject"
        :redirect-to="redirectTo"
        :row-index="entry.rowIndex"
        class="w-full"
    />
</template>
