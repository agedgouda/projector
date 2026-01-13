<script setup lang="ts">
import DocumentList from '@/components/documents/DocumentList.vue';

defineProps<{
    documents: ProjectDocument[];
    project: Project;
    projectTypes: ProjectType[];
    users: User[];
    stats: any;
}>();


</script>

<template>

        <h2 class="text-xl font-bold mb-2">{{ project.name }}:
            <span class="text-sm font-bold text-muted-foreground mb-3">{{ stats.total }} Tasks
            </span>
        </h2>

        <div class="space-y-4">
            <DocumentList
                :documents="documents"
                :users="users"
            />
        </div>
</template>
