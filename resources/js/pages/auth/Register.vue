<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBrandLayout from '@/layouts/auth/AuthBrandLayout.vue';
import { login } from '@/routes';
import { Form, Head } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { Check, Eye, EyeOff } from 'lucide-vue-next';

const props = defineProps<{
    turnstileSiteKey?: string | null;
}>();

const showPassword = ref(false);
const showPasswordConfirmation = ref(false);

const TURNSTILE_SCRIPT_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

const turnstileContainer = ref<HTMLElement | null>(null);

type TurnstileApi = {
    render: (container: HTMLElement, options: { sitekey: string }) => void;
    reset: () => void;
};

const turnstileApi = (): TurnstileApi | undefined => (window as unknown as { turnstile?: TurnstileApi }).turnstile;

onMounted(() => {
    if (!props.turnstileSiteKey) {
        return;
    }

    // The script only auto-renders .cf-turnstile elements present when it first loads, so on a
    // later Inertia visit (or HMR) where it's already loaded, the widget has to be rendered by hand.
    if (document.querySelector(`script[src="${TURNSTILE_SCRIPT_SRC}"]`)) {
        const api = turnstileApi();

        if (api && turnstileContainer.value && !turnstileContainer.value.hasChildNodes()) {
            api.render(turnstileContainer.value, { sitekey: props.turnstileSiteKey });
        }

        return;
    }

    const script = document.createElement('script');
    script.src = TURNSTILE_SCRIPT_SRC;
    script.async = true;
    script.defer = true;
    document.head.appendChild(script);
});

// Turnstile tokens are single-use, and the server validates every field regardless of
// which one actually fails, so any failed submission (wrong password confirmation, a
// taken email, etc.) burns the token against Cloudflare even though Turnstile itself
// passed. Since Inertia's <Form> never reloads the page, the widget would otherwise keep
// showing "Success!" from the original solve while silently resending that dead token on
// the next attempt - resetting it here gets the next submit a fresh one.
const resetTurnstile = (): void => {
    turnstileApi()?.reset();
};
</script>

<template>
    <AuthBrandLayout title="Create your account" description="Get started for free in seconds.">
        <Head title="Register" />

        <template #aside>
            <h1 class="text-4xl leading-tight font-bold tracking-tight text-foreground xl:text-[2.75rem]">
                Built For You
            </h1>

            <p class="text-sm leading-relaxed text-muted-foreground">
                Let <strong class="font-bold text-projector-primary-500">Projector</strong> handle the busywork
            </p>

            <ul class="flex flex-col gap-3 text-sm text-muted-foreground">
                <li class="flex gap-2.5">
                    <Check class="mt-0.5 size-4 shrink-0 text-primary" />
                    <span><strong class="font-semibold text-foreground">Free 14-day trial</strong> — No credit card required.</span>
                </li>
                <li class="flex gap-2.5">
                    <Check class="mt-0.5 size-4 shrink-0 text-primary" />
                    <span><strong class="font-semibold text-foreground">Instant setup</strong> — Get up and running in under 2 minutes.</span>
                </li>
                <li class="flex gap-2.5">
                    <Check class="mt-0.5 size-4 shrink-0 text-primary" />
                    <span><strong class="font-semibold text-foreground">Client ready</strong> — Automatically generate custom status reports on demand.</span>
                </li>
            </ul>

            <figure class="mt-2 flex flex-col gap-3 rounded-lg border border-l-4 border-border border-l-primary bg-white/60 p-5 dark:bg-zinc-800/60">
                <blockquote class="text-sm leading-relaxed text-foreground">
                    Projector has saved our team 5+ hours a week on client updates.
                </blockquote>
                <figcaption class="flex items-center gap-2 text-xs text-muted-foreground">
                    <span class="flex size-6 items-center justify-center rounded-full bg-projector-primary-100 text-[0.65rem] font-semibold text-projector-primary-700">LJ</span>
                    Lindsay J., Agency Founder
                </figcaption>
            </figure>
        </template>

        <Form
            action="/register"
            method="post"
            :reset-on-success="['password', 'password_confirmation']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-5"
            @error="resetTurnstile"
        >
            <div class="grid gap-4">
                <!-- Honeypot: invisible and unreachable by keyboard/screen reader for a real
                     visitor, but a bot that blindly fills every field it finds will trip the
                     "prohibited" rule in CreateNewUser and fail like any other bad input. -->
                <div class="absolute left-[-9999px] top-auto w-px h-px overflow-hidden" aria-hidden="true">
                    <label for="website">Website</label>
                    <input id="website" type="text" name="website" tabindex="-1" autocomplete="off" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1.5">
                        <Label for="first_name">First name</Label>
                        <Input
                            id="first_name"
                            type="text"
                            required
                            autofocus
                            :tabindex="1"
                            autocomplete="given-name"
                            name="first_name"
                            placeholder="Jane"
                        />
                        <InputError :message="errors.first_name" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="last_name">Last name</Label>
                        <Input
                            id="last_name"
                            type="text"
                            required
                            :tabindex="2"
                            autocomplete="family-name"
                            name="last_name"
                            placeholder="Doe"
                        />
                        <InputError :message="errors.last_name" />
                    </div>
                </div>

                <div class="grid gap-1.5">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        type="email"
                        required
                        :tabindex="3"
                        autocomplete="email"
                        name="email"
                        placeholder="email@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="password">Password</Label>
                    <div class="relative">
                        <Input
                            id="password"
                            :type="showPassword ? 'text' : 'password'"
                            required
                            :tabindex="4"
                            autocomplete="new-password"
                            name="password"
                            placeholder="Password"
                            class="pr-10"
                        />
                        <button
                            type="button"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                            @click="showPassword = !showPassword"
                            tabindex="-1"
                        >
                            <Eye v-if="!showPassword" class="h-4 w-4" />
                            <EyeOff v-else class="h-4 w-4" />
                        </button>
                    </div>
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="password_confirmation">Confirm password</Label>
                    <div class="relative">
                        <Input
                            id="password_confirmation"
                            :type="showPasswordConfirmation ? 'text' : 'password'"
                            required
                            :tabindex="5"
                            autocomplete="new-password"
                            name="password_confirmation"
                            placeholder="Confirm password"
                            class="pr-10"
                        />
                        <button
                            type="button"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                            @click="showPasswordConfirmation = !showPasswordConfirmation"
                            tabindex="-1"
                        >
                            <Eye v-if="!showPasswordConfirmation" class="h-4 w-4" />
                            <EyeOff v-else class="h-4 w-4" />
                        </button>
                    </div>
                    <InputError :message="errors.password_confirmation" />
                </div>

                <div v-if="turnstileSiteKey" class="grid justify-items-center gap-2">
                    <div ref="turnstileContainer" class="cf-turnstile" :data-sitekey="turnstileSiteKey"></div>
                    <InputError :message="errors['cf-turnstile-response']" />
                </div>

                <div class="flex flex-col gap-2">
                    <Button
                        type="submit"
                        class="w-full cursor-pointer text-xs font-semibold tracking-wide uppercase"
                        :tabindex="6"
                        :disabled="processing"
                        data-test="register-user-button"
                    >
                        <Spinner v-if="processing" />
                        Create free account
                    </Button>
                    <p class="text-center text-xs text-muted-foreground">
                        No credit card required. Cancel anytime.
                    </p>
                </div>
            </div>

            <div class="text-center text-sm text-foreground">
                Already have an account?
                <TextLink
                    :href="login()"
                    class="underline underline-offset-4 cursor-pointer"
                    :tabindex="7"
                    >Log in</TextLink
                >
            </div>
        </Form>
    </AuthBrandLayout>
</template>
