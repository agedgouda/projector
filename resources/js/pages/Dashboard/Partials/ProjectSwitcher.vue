<script setup lang="ts">
import { Folder, ChevronDown } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

defineProps<{
    projects: Project[];
    currentProject: Project | null;
}>();

const emit = defineEmits<{
    (e: 'switch', id: string): void;
}>();
</script>

<template>
    <DropdownMenu v-if="projects.length > 0">
        <DropdownMenuTrigger as-child>
            <Button variant="ghost" class="h-14 px-5 rounded-2xl bg-white border border-gray-100 flex items-center gap-4 hover:bg-gray-50 transition-all shadow-sm">
                <div class="w-10 h-10 rounded-xl bg-indigo-600 flex items-center justify-center text-white">
                    <Folder class="w-5 h-5" />
                </div>
                <div class="text-left">
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400 leading-none mb-1.5">Active Project</p>
                    <p class="text-base font-bold text-gray-900">{{ currentProject?.name ?? 'Select Project' }}</p>
                </div>
                <ChevronDown class="w-4 h-4 text-gray-300 ml-2" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" class="w-72 rounded-xl p-2">
            <DropdownMenuItem v-for="p in projects" :key="p.id" @click="emit('switch', p.id)" class="p-3 cursor-pointer rounded-lg">
                <span class="font-bold text-sm">{{ p.name }}</span>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
