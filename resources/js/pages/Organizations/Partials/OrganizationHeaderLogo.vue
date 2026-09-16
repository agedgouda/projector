<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Building2, Pencil, X } from 'lucide-vue-next';
import { resizeImage } from '@/lib/resizeImage';
import organizationLogoRoutes from '@/routes/organizations/logo/index';

interface Props {
    organization: Organization & { logo_url?: string | null };
}

const props = defineProps<Props>();

const fileInput = ref<HTMLInputElement | null>(null);
const previewUrl = ref<string | null>(null);
const uploadForm = useForm({ logo: null as File | null });
const deleteForm = useForm({});

const displayUrl = computed(() => previewUrl.value ?? props.organization.logo_url ?? null);

async function onFileChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) { return; }

    const resized = await resizeImage(file);
    uploadForm.logo = resized;
    previewUrl.value = URL.createObjectURL(resized);

    uploadForm.post(organizationLogoRoutes.store.url(props.organization.id), {
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
            if (fileInput.value) { fileInput.value.value = ''; }
        },
    });
}

function removeLogo() {
    deleteForm.delete(organizationLogoRoutes.destroy.url(props.organization.id), { preserveScroll: true });
}
</script>

<template>
    <div class="group relative size-16 shrink-0">
        <div
            v-if="displayUrl"
            class="size-16 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-zinc-700"
        >
            <img
                :src="displayUrl"
                :alt="organization.name"
                class="size-full object-contain"
                :class="{ 'opacity-50': uploadForm.processing }"
            />
        </div>
        <div
            v-else
            class="flex size-16 items-center justify-center rounded-xl border border-gray-200 bg-gray-50 dark:border-zinc-700 dark:bg-zinc-800"
        >
            <Building2 class="h-8 w-8 text-gray-300 dark:text-zinc-600" />
        </div>

        <button
            type="button"
            class="absolute inset-0 flex items-center justify-center rounded-xl opacity-0 transition-all group-hover:bg-black/40 group-hover:opacity-100"
            :disabled="uploadForm.processing"
            @click="fileInput?.click()"
        >
            <Pencil class="h-5 w-5 text-white" />
        </button>

        <button
            v-if="organization.logo_url && !uploadForm.processing"
            type="button"
            class="absolute -top-1.5 -right-1.5 flex size-5 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 opacity-0 shadow transition-opacity group-hover:opacity-100 hover:text-red-500 dark:border-zinc-700 dark:bg-zinc-800"
            @click.stop="removeLogo"
        >
            <X class="h-3 w-3" />
        </button>

        <input
            ref="fileInput"
            type="file"
            accept="image/jpeg,image/png,image/webp,image/gif"
            class="hidden"
            @change="onFileChange"
        />
    </div>
</template>
