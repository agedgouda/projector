<template>
    <div class="min-h-screen bg-light-background">
        <slot name="header" />

        <main class="w-full px-6 py-6">
            <div class="grid grid-cols-12 gap-12">
                <div class="col-span-12 lg:col-span-8">
                    <slot name="content" />
                </div>

                <aside class="col-span-12 lg:col-span-4">
                    <slot name="sidebar" />
                </aside>
            </div>
        </main>
    </div>
</template>

<style scoped>
/* All your specific document UI CSS moved here */
:deep(.custom-scrollbar::-webkit-scrollbar) { width: 6px; }
:deep(.custom-scrollbar::-webkit-scrollbar-thumb) { background: #e2e8f0; border-radius: 10px; }

:deep([data-radix-collection-item]),
:deep(button[role="combobox"]) {
    outline: none !important;
    box-shadow: none !important;
    border-color: transparent !important;
}

:deep(button[role="combobox"]:focus) {
    background-color: rgb(241 245 249);
}
</style>
