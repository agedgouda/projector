<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useIdleSessionTimer } from '@/composables/useIdleSessionTimer';
import { computed } from 'vue';

const { showWarning, remainingSeconds, isExtending, stayLoggedIn, logoutNow } =
    useIdleSessionTimer();

const countdownLabel = computed(() => {
    const minutes = Math.floor(remainingSeconds.value / 60);
    const seconds = remainingSeconds.value % 60;
    return `${minutes}:${String(seconds).padStart(2, '0')}`;
});
</script>

<template>
    <Dialog :open="showWarning">
        <DialogContent
            class="sm:max-w-[425px]"
            :show-close-button="false"
            @escape-key-down.prevent
            @pointer-down-outside.prevent
        >
            <DialogHeader>
                <DialogTitle>Still there?</DialogTitle>
                <DialogDescription>
                    You've been inactive for a while. For your security, you'll
                    be logged out in
                    <span class="font-bold text-foreground">{{
                        countdownLabel
                    }}</span>
                    unless you choose to stay.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2 sm:gap-4">
                <Button
                    variant="outline"
                    @click="logoutNow"
                    :disabled="isExtending"
                >
                    Log Out
                </Button>
                <Button @click="stayLoggedIn" :disabled="isExtending">
                    {{ isExtending ? 'Please wait...' : 'Stay Logged In' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
