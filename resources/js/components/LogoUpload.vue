<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { resizeImage } from '@/lib/resizeImage';

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

async function onFileChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) { return; }

    const resized = await resizeImage(file);
    uploadForm.logo = resized;
    previewUrl.value = URL.createObjectURL(resized);
}

function upload() {
    uploadForm.post(props.uploadUrl, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            previewUrl.value = null;
            uploadForm.reset();
            if (fileInput.value) { fileInput.value.value = ''; }
        },
    });
}

function cancelPreview() {
    previewUrl.value = null;
    uploadForm.reset();
    if (fileInput.value) { fileInput.value.value = ''; }
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
            <div class="size-20 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 flex items-center justify-center overflow-hidden shrink-0">
                <img
                    v-if="displayUrl"
                    :src="displayUrl"
                    alt="Logo preview"
                    class="size-full object-contain"
                />
                <span v-else class="text-xs text-gray-400 dark:text-gray-500 text-center px-2">No logo</span>
            </div>

            <div class="space-y-2">
                <div class="flex items-center gap-2 flex-wrap">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="fileInput?.click()"
                    >
                        {{ currentLogoUrl ? 'Change' : 'Upload' }}
                    </Button>

                    <Button
                        v-if="previewUrl"
                        type="button"
                        size="sm"
                        :disabled="uploadForm.processing"
                        @click="upload"
                    >
                        {{ uploadForm.processing ? 'Saving…' : 'Save' }}
                    </Button>

                    <Button
                        v-if="previewUrl"
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="cancelPreview"
                    >
                        Cancel
                    </Button>

                    <Button
                        v-if="currentLogoUrl && !previewUrl"
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

                <p class="text-xs text-gray-500 dark:text-gray-400">JPEG, PNG, WebP or GIF · max 5 MB</p>

                <p v-if="uploadForm.errors.logo" class="text-xs text-destructive">{{ uploadForm.errors.logo }}</p>
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
