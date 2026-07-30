<script setup lang="ts">
import { usePage, router } from '@inertiajs/vue3';
import { UserCog } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { destroy as stopImpersonating } from '@/actions/App/Http/Controllers/ImpersonationController';

const page = usePage<AppPageProps>();

const stop = () => {
    router.delete(stopImpersonating().url);
};
</script>

<template>
    <div
        v-if="page.props.auth.impersonating"
        class="sticky top-0 z-50 flex items-center justify-center gap-3 bg-amber-500 px-4 py-2 text-[11px] font-black uppercase tracking-widest text-amber-950"
    >
        <UserCog class="h-3.5 w-3.5" />
        <span>
            Logged in as {{ page.props.auth.user.name }}
        </span>
        <Button
            size="sm"
            variant="secondary"
            class="h-6 bg-amber-950 px-2 text-[10px] font-black uppercase tracking-widest text-amber-50 hover:bg-amber-900"
            @click="stop"
        >
            Stop Impersonating
        </Button>
    </div>
</template>
