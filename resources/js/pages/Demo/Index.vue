<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AuthBrandLayout from '@/layouts/auth/AuthBrandLayout.vue';
import { start } from '@/routes/demo';
import { Form, Head } from '@inertiajs/vue3';
import { Check, Files } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';

const props = defineProps<{
    projects: { name: string; client: string; meeting: string }[];
    lifetimeHours: number;
    isSignedIn: boolean;
    turnstileSiteKey?: string | null;
}>();

const TURNSTILE_SCRIPT_SRC =
    'https://challenges.cloudflare.com/turnstile/v0/api.js';

const turnstileContainer = ref<HTMLElement | null>(null);

type TurnstileApi = {
    render: (container: HTMLElement, options: { sitekey: string }) => void;
    reset: () => void;
};

const turnstileApi = (): TurnstileApi | undefined =>
    (window as unknown as { turnstile?: TurnstileApi }).turnstile;

onMounted(() => {
    if (!props.turnstileSiteKey) {
        return;
    }

    // The script only auto-renders .cf-turnstile elements present when it first loads, so on a
    // later Inertia visit where it's already loaded, the widget has to be rendered by hand.
    if (document.querySelector(`script[src="${TURNSTILE_SCRIPT_SRC}"]`)) {
        const api = turnstileApi();

        if (
            api &&
            turnstileContainer.value &&
            !turnstileContainer.value.hasChildNodes()
        ) {
            api.render(turnstileContainer.value, {
                sitekey: props.turnstileSiteKey,
            });
        }

        return;
    }

    const script = document.createElement('script');
    script.src = TURNSTILE_SCRIPT_SRC;
    script.async = true;
    script.defer = true;
    document.head.appendChild(script);
});

// Tokens are single-use, so a failed attempt needs a fresh one.
const resetTurnstile = (): void => {
    turnstileApi()?.reset();
};
</script>

<template>
    <AuthBrandLayout
        title="Try Projector"
        description="Explore a working demo account. No sign-up needed."
    >
        <Head title="Demo" />

        <template #aside>
            <p class="text-sm leading-relaxed text-muted-foreground">
                What you'll do in
                <strong class="font-bold text-projector-primary-500"
                    >Projector</strong
                >
            </p>

            <ul class="flex flex-col gap-3 text-sm text-muted-foreground">
                <li class="flex gap-2.5">
                    <Check class="mt-0.5 size-4 shrink-0 text-primary" />
                    <span
                        ><strong class="font-semibold text-foreground"
                            >Watch AI write Meeting Notes</strong
                        >
                        from a real meeting transcript.</span
                    >
                </li>
                <li class="flex gap-2.5">
                    <Check class="mt-0.5 size-4 shrink-0 text-primary" />
                    <span
                        ><strong class="font-semibold text-foreground"
                            >Transform them into tasks</strong
                        >
                        with priorities and due dates.</span
                    >
                </li>
                <li class="flex gap-2.5">
                    <Check class="mt-0.5 size-4 shrink-0 text-primary" />
                    <span
                        ><strong class="font-semibold text-foreground"
                            >Run the project</strong
                        >
                        on the Kanban board and calendar.</span
                    >
                </li>
            </ul>
        </template>

        <Form
            v-bind="start.form()"
            class="flex flex-col gap-5"
            v-slot="{ errors, processing }"
            @error="resetTurnstile"
        >
            <div class="flex flex-col gap-2">
                <p
                    class="text-[11px] font-black tracking-widest text-slate-400 uppercase"
                >
                    Your demo projects
                </p>
                <ul class="flex flex-col gap-2">
                    <li
                        v-for="project in projects"
                        :key="project.name"
                        class="flex gap-2.5 rounded-lg border border-slate-200 p-3 dark:border-white/10"
                    >
                        <Files class="mt-0.5 size-4 shrink-0 text-slate-400" />
                        <div class="flex flex-col">
                            <span
                                class="text-sm font-semibold text-foreground"
                                >{{ project.name }}</span
                            >
                            <span class="text-xs text-muted-foreground"
                                >{{ project.client }} ·
                                {{ project.meeting }}</span
                            >
                        </div>
                    </li>
                </ul>
            </div>

            <div
                v-if="turnstileSiteKey"
                ref="turnstileContainer"
                class="cf-turnstile"
                :data-sitekey="turnstileSiteKey"
            />
            <InputError
                :message="errors['cf-turnstile-response'] ?? errors.demo"
            />

            <Button type="submit" class="w-full" :disabled="processing">
                <Spinner v-if="processing" />
                Start the demo
            </Button>

            <p
                class="text-center text-xs leading-relaxed text-muted-foreground"
            >
                <template v-if="isSignedIn"
                    >Starting the demo signs you out of your account.
                </template>
                The demo account and everything in it is deleted after
                {{ lifetimeHours }} hours. By starting it you agree to the
                <a href="/terms" class="underline underline-offset-2"
                    >Terms of Service</a
                >
                and
                <a href="/privacy" class="underline underline-offset-2"
                    >Privacy Policy</a
                >.
            </p>
        </Form>
    </AuthBrandLayout>
</template>
