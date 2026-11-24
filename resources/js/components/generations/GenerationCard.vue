<script setup lang="ts">
import { ImageOff } from '@lucide/vue';
import { computed, ref } from 'vue';
import GenerationStatusBadge from '@/components/generations/GenerationStatusBadge.vue';
import { exactUsd, formatUsd } from '@/lib/money';
import type { Generation } from '@/types';

const props = defineProps<{
    generation: Generation;
}>();

const imageFailed = ref(false);

const aspectClass = computed(
    () =>
        ({
            '1024x1024': 'aspect-square',
            '1536x1024': 'aspect-[3/2]',
            '1024x1536': 'aspect-[2/3]',
        })[props.generation.size] ?? 'aspect-square',
);

const isActive = computed(() =>
    ['pending', 'queued', 'processing'].includes(props.generation.status),
);

const imageExpired = computed(
    () =>
        props.generation.image_url_expires_at !== null &&
        new Date(props.generation.image_url_expires_at) < new Date(),
);

const statusMessage = computed(() => {
    const generation = props.generation;

    if (generation.status_detail) {
        return generation.status_detail;
    }

    return (
        {
            pending: 'Sending to Pixelforge…',
            queued: 'Waiting in Pixelforge’s queue…',
            processing: 'Painting pixels… usually 5–30 seconds.',
        } as Record<string, string>
    )[generation.status];
});

const createdAt = computed(() =>
    new Date(props.generation.created_at).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }),
);
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
    >
        <div
            :class="[
                aspectClass,
                'relative flex max-h-96 w-full items-center justify-center overflow-hidden bg-muted',
            ]"
        >
            <img
                v-if="
                    generation.status === 'succeeded' &&
                    generation.image_url &&
                    !imageFailed
                "
                :src="generation.image_url"
                :alt="generation.prompt"
                class="size-full object-cover"
                loading="lazy"
                @error="imageFailed = true"
            />
            <div
                v-else-if="generation.status === 'succeeded'"
                class="flex flex-col items-center gap-2 p-4 text-center text-sm text-muted-foreground"
            >
                <ImageOff class="size-6" />
                <span>
                    {{
                        imageExpired
                            ? 'This image link expired 24 hours after it was made.'
                            : 'The image couldn’t be loaded.'
                    }}
                </span>
            </div>
            <div
                v-else-if="isActive"
                class="absolute inset-0 animate-pulse bg-gradient-to-br from-muted via-muted-foreground/10 to-muted"
            />
            <div
                v-else
                class="flex flex-col items-center gap-2 p-6 text-center text-sm text-red-700 dark:text-red-300"
            >
                <ImageOff class="size-6" />
                <span>{{ generation.error_message }}</span>
            </div>

            <p
                v-if="isActive"
                class="relative px-4 text-center text-sm text-muted-foreground"
            >
                {{ statusMessage }}
            </p>
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex items-center justify-between gap-2">
                <GenerationStatusBadge :status="generation.status" />
                <span class="text-xs text-muted-foreground">{{
                    createdAt
                }}</span>
            </div>

            <p class="line-clamp-3 text-sm" :title="generation.prompt">
                {{ generation.prompt }}
            </p>

            <div class="flex flex-wrap gap-1.5 text-xs text-muted-foreground">
                <span class="rounded bg-muted px-1.5 py-0.5">{{
                    generation.size
                }}</span>
                <span class="rounded bg-muted px-1.5 py-0.5 capitalize">{{
                    generation.quality
                }}</span>
                <span
                    v-if="generation.reference_images.length"
                    class="rounded bg-muted px-1.5 py-0.5"
                    >{{ generation.reference_images.length }} reference image{{
                        generation.reference_images.length > 1 ? 's' : ''
                    }}</span
                >
            </div>

            <dl
                class="mt-auto grid grid-cols-2 gap-2 border-t border-sidebar-border/70 pt-3 text-xs dark:border-sidebar-border"
            >
                <div>
                    <dt class="text-muted-foreground">Estimated</dt>
                    <dd class="font-medium tabular-nums">
                        {{ formatUsd(generation.estimated_min_charge_nanos) }}
                        –
                        {{ formatUsd(generation.estimated_max_charge_nanos) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Charged</dt>
                    <dd
                        v-if="generation.status === 'succeeded'"
                        class="font-medium tabular-nums"
                        :title="
                            generation.charged_nanos !== null
                                ? exactUsd(generation.charged_nanos)
                                : undefined
                        "
                    >
                        {{ formatUsd(generation.charged_nanos ?? 0) }}
                    </dd>
                    <dd
                        v-else-if="generation.status === 'failed'"
                        class="font-medium text-emerald-700 dark:text-emerald-400"
                    >
                        $0.00 — not charged
                    </dd>
                    <dd v-else class="text-muted-foreground">On completion</dd>
                </div>
            </dl>
        </div>
    </article>
</template>
