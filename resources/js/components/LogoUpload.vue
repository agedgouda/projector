<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { resizeImage } from '@/lib/resizeImage';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    currentLogoUrl: string | null;
    uploadUrl: string;
    deleteUrl: string;
    label?: string;
}>();

const fileInput = ref<HTMLInputElement | null>(null);
const previewUrl = ref<string | null>(null);

const uploadForm = useForm({ logo: null as File | null });
const deleteForm = useForm({});

// Uploads immediately on selection rather than staging behind a separate "Save" click —
// that click lived outside the surrounding entry form's own Save/Update button, so picking
// a logo there while editing an existing record silently never persisted it: the outer
// form's own submit reported success (for the other fields) without ever touching this
// widget's still-unsaved file.
async function onFileChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) {
        return;
    }

    const resized = await resizeImage(file);
    uploadForm.logo = resized;
    previewUrl.value = URL.createObjectURL(resized);

    uploadForm.post(props.uploadUrl, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            previewUrl.value = null;
            uploadForm.reset();
        },
        onError: () => {
            previewUrl.value = null;
        },
        onFinish: () => {
            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}

function removeLogo() {
    deleteForm.delete(props.deleteUrl, { preserveScroll: true });
}

const displayUrl = computed(() => previewUrl.value ?? props.currentLogoUrl);
</script>

<template>
    <div class="space-y-3">
        <Label>{{ label ?? 'Logo' }}</Label>

        <div class="flex items-center gap-4">
            <div
                class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700"
                :class="displayUrl ? 'bg-white' : 'bg-gray-50 dark:bg-gray-800'"
            >
                <img
                    v-if="displayUrl"
                    :src="displayUrl"
                    alt="Logo preview"
                    class="size-full object-contain"
                    :class="{ 'opacity-50': uploadForm.processing }"
                />
                <span
                    v-else
                    class="px-2 text-center text-xs text-gray-400 dark:text-gray-500"
                    >No logo</span
                >
            </div>

            <div class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="uploadForm.processing"
                        @click="fileInput?.click()"
                    >
                        {{
                            uploadForm.processing
                                ? 'Uploading…'
                                : currentLogoUrl
                                  ? 'Change'
                                  : 'Upload'
                        }}
                    </Button>

                    <Button
                        v-if="currentLogoUrl && !uploadForm.processing"
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="text-destructive hover:text-destructive"
                        :disabled="deleteForm.processing"
                        @click="removeLogo"
                    >
                        Remove
                    </Button>
                </div>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    JPEG, PNG, WebP or GIF · max 5 MB
                </p>

                <p
                    v-if="uploadForm.errors.logo"
                    class="text-xs text-destructive"
                >
                    {{ uploadForm.errors.logo }}
                </p>
            </div>
        </div>

        <input
            ref="fileInput"
            type="file"
            accept="image/jpeg,image/png,image/webp,image/gif"
            class="hidden"
            @change="onFileChange"
        />
    </div>
</template>
