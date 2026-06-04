<script setup lang="ts">
import { dashboard, login, register } from '@/routes';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { computed } from 'vue';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);

const page = usePage<AppPageProps>();
const authUser = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Welcome">
    </Head>
    <div class="flex min-h-screen flex-col bg-[#FDFDFC] dark:bg-light-background text-[#1b1b18] dark:text-slate-200">
        <header class="w-full p-6 text-sm not-has-[nav]:hidden">
            <nav class="flex items-center justify-end gap-4">
                <Link
                    v-if="authUser"
                    :href="dashboard()"
                    class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-white/20 dark:text-slate-200 dark:hover:border-white/40"
                >
                    Dashboard
                </Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="inline-block rounded-sm border border-transparent px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#19140035] dark:text-slate-200 dark:hover:border-white/20"
                    >
                        Log in
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="register()"
                        class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-white/20 dark:text-slate-200 dark:hover:border-white/40"
                    >
                        Register
                    </Link>
                </template>
            </nav>
        </header>
        <main class="flex flex-1 items-center justify-center p-6">
            <AppLogoIcon class="w-full max-w-[min(80vw,80vh)] h-auto" />
        </main>
    </div>
</template>
