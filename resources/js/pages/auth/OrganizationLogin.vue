<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { request } from '@/routes/password';
import { store } from '@/actions/App/Http/Controllers/OrganizationLoginController';
import { Form, Head } from '@inertiajs/vue3';

const props = defineProps<{
    organization: { id: string; name: string };
    canResetPassword: boolean;
    status?: string;
    invitedEmail?: string | null;
    invitationToken?: string | null;
}>();

const page = usePage<AppPageProps>();

const displayStatus = computed(() => {
    if (page.url.includes('expired=1')) {
        return 'Your session expired. Please log in again.';
    }
    return props.status;
});
</script>

<template>
    <AuthBase
        :title="`Log in to ${organization.name}`"
        description="Enter your email and password below to log in"
    >
        <Head title="Log in" />

        <div
            v-if="displayStatus"
            class="mb-4 text-center text-sm font-medium text-green-600"
        >
            {{ displayStatus }}
        </div>

        <Form
            :action="store(organization).url"
            method="post"
            :reset-on-success="['password']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <input v-if="invitationToken" type="hidden" name="invitation_token" :value="invitationToken" />

            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        placeholder="email@example.com"
                        :model-value="invitedEmail ?? undefined"
                        :readonly="!!invitedEmail"
                        :class="{ 'bg-slate-50 text-slate-500': !!invitedEmail }"
                    />
                    <InputError :message="errors.email" />
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
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        placeholder="Password"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="flex items-center justify-between">
                    <Label for="remember" class="flex items-center space-x-3">
                        <Checkbox id="remember" name="remember" :tabindex="3" />
                        <span>Remember me</span>
                    </Label>
                </div>

                <Button
                    type="submit"
                    class="mt-4 w-full"
                    :tabindex="4"
                    :disabled="processing"
                >
                    <Spinner v-if="processing" />
                    Log in
                </Button>
            </div>
        </Form>
    </AuthBase>
</template>
