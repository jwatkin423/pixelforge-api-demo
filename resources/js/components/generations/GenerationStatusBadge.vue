<script setup lang="ts">
import { CircleAlert, CircleCheck, Clock } from '@lucide/vue';
import { computed } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type { GenerationStatus } from '@/types';

const props = defineProps<{
    status: GenerationStatus;
}>();

const appearance = computed(
    () =>
        ({
            pending: {
                label: 'Submitting',
                classes:
                    'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
            },
            queued: {
                label: 'Queued',
                classes:
                    'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
            },
            processing: {
                label: 'Processing',
                classes:
                    'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
            },
            succeeded: {
                label: 'Succeeded',
                classes:
                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
            },
            failed: {
                label: 'Failed',
                classes:
                    'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
            },
        })[props.status],
);
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                appearance.classes,
            )
        "
    >
        <Clock v-if="status === 'queued'" class="size-3" />
        <Spinner
            v-else-if="status === 'pending' || status === 'processing'"
            class="size-3"
        />
        <CircleCheck v-else-if="status === 'succeeded'" class="size-3" />
        <CircleAlert v-else class="size-3" />
        {{ appearance.label }}
    </span>
</template>
