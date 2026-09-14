<script setup lang="ts">
import { computed, ref } from 'vue';
import axios from 'axios';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { usePage, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { register } from '@/routes';
import { request } from '@/routes/password';
import { Head } from '@inertiajs/vue3';
import { Eye, EyeOff } from 'lucide-vue-next';

const showPassword = ref(false);

const props = defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}>();

const page = usePage<AppPageProps>();

const displayStatus = computed(() => {
    // 1. Check if the URL has our expired flag
    if (page.url.includes('expired=1')) {
        return 'Your session expired. Please log in again.';
    }
    // 2. Fallback to the actual status prop from Laravel
    return props.status;
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

// A tab left open for hours — or one that already landed here once from a session timeout —
// can be holding a CSRF token that's gone stale by the time the user actually clicks Log In.
// Left alone, that first submit 419s and silently round-trips through a fresh copy of this
// same page (see bootstrap/app.php's 419 handler), so the user has to retype everything and
// click again. Refreshing the token immediately before posting means the login request always
// carries a valid one, so the first click works even after a long idle stretch.
const submit = async () => {
    await axios.get('/sanctum/csrf-cookie');
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthBase
        title="Log in to your account"
        description="Enter your email and password below to log in"
    >
        <Head title="Log in" />

        <div
            v-if="displayStatus"
            class="mb-4 text-center text-sm font-medium text-green-600"
        >
            {{ displayStatus }}
        </div>

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        placeholder="email@example.com"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">Password</Label>
                        <TextLink
                            v-if="canResetPassword"
                            :href="request()"
                            class="text-sm"
                            :tabindex="5"
                        >
                            Forgot password?
                        </TextLink>
                    </div>
                    <div class="relative">
                        <Input
                            id="password"
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            required
                            :tabindex="2"
                            autocomplete="current-password"
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
                    <InputError :message="form.errors.password" />
                </div>

                <div class="flex items-center justify-between">
                    <Label for="remember" class="flex items-center space-x-3">
                        <Checkbox id="remember" v-model="form.remember" :tabindex="3" />
                        <span>Remember me</span>
                    </Label>
                </div>

                <Button
                    type="submit"
                    class="mt-4 w-full"
                    :tabindex="4"
                    :disabled="form.processing"
                    data-test="login-button"
                >
                    <Spinner v-if="form.processing" />
                    Log in
                </Button>
            </div>

            <div
                class="text-center text-sm text-muted-foreground"
                v-if="canRegister"
            >
                Don't have an account?
                <TextLink :href="register()" :tabindex="5">Sign up</TextLink>
            </div>
        </form>
    </AuthBase>
</template>
