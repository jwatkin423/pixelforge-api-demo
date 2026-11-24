<script setup lang="ts">
import {
    Head,
    Link,
    useForm,
    useHttp,
    usePage,
    usePoll,
} from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { Info, Sparkles, Wallet } from '@lucide/vue';
import { computed, onMounted, watch } from 'vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import ReferenceImageInputs from '@/components/generations/ReferenceImageInputs.vue';
import InputError from '@/components/input/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatUsd } from '@/lib/money';
import { cn } from '@/lib/utils';
import { index as creditsIndex } from '@/routes/credits';
import {
    create,
    estimate as estimateRoute,
    index as historyIndex,
    store,
} from '@/routes/generations';
import type { CostEstimate, Generation, Option } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: create() }],
    },
});

const props = defineProps<{
    sizes: Option[];
    qualities: Option[];
    recentGenerations: Generation[];
    hasActiveGenerations: boolean;
}>();

const MAX_PROMPT_LENGTH = 4000;
const MAX_REFERENCE_IMAGES = 4;

const page = usePage();
const balanceNanos = computed(() => page.props.auth.user.balance_nanos);

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

const estimator = useHttp<
    {
        prompt: string;
        size: string;
        quality: string;
        reference_image_count: number;
    },
    CostEstimate
>({
    prompt: '',
    size: form.size,
    quality: form.quality,
    reference_image_count: 0,
});

function refreshEstimate(): void {
    estimator.prompt = form.prompt;
    estimator.size = form.size;
    estimator.quality = form.quality;
    estimator.reference_image_count = form.reference_images.filter(
        (url) => url.trim() !== '',
    ).length;

    void estimator.post(estimateRoute().url).catch(() => undefined);
}

onMounted(refreshEstimate);

watchDebounced(
    () => [
        form.prompt,
        form.size,
        form.quality,
        form.reference_images.filter((url) => url.trim() !== '').length,
    ],
    refreshEstimate,
    { debounce: 250 },
);

const estimate = computed(() => estimator.response);

const balanceError = computed(
    () => (form.errors as Record<string, string | undefined>).balance,
);

const canAfford = computed(
    () =>
        estimate.value === null ||
        balanceNanos.value >= estimate.value.max_charge_nanos,
);

const canSubmit = computed(
    () =>
        form.prompt.trim() !== '' &&
        form.prompt.length <= MAX_PROMPT_LENGTH &&
        canAfford.value &&
        !form.processing,
);

const poll = usePoll(
    3000,
    { only: ['recentGenerations', 'hasActiveGenerations', 'auth'] },
    { autoStart: props.hasActiveGenerations },
);

watch(
    () => props.hasActiveGenerations,
    (active) => (active ? poll.start() : poll.stop()),
);

function submit(): void {
    form.transform((data) => ({
        ...data,
        reference_images: data.reference_images.filter(
            (url) => url.trim() !== '',
        ),
    })).post(store().url, {
        preserveScroll: true,
        onSuccess: () => form.reset('prompt', 'reference_images'),
    });
}

const sizeShapes: Record<string, string> = {
    '1024x1024': 'h-6 w-6',
    '1536x1024': 'h-5 w-7',
    '1024x1536': 'h-7 w-5',
};
</script>

<template>
    <Head title="Generate" />

    <div class="flex flex-1 flex-col gap-8 p-4 md:p-6">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <form class="flex flex-col gap-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <div class="flex items-end justify-between">
                        <Label for="prompt" class="text-base">Prompt</Label>
                        <span
                            :class="
                                cn(
                                    'text-xs tabular-nums',
                                    form.prompt.length > MAX_PROMPT_LENGTH
                                        ? 'text-red-600'
                                        : 'text-muted-foreground',
                                )
                            "
                        >
                            {{ form.prompt.length.toLocaleString() }} /
                            {{ MAX_PROMPT_LENGTH.toLocaleString() }}
                        </span>
                    </div>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        v-focus
                        rows="5"
                        placeholder="A lighthouse on a cliff at dawn, oil painting"
                        class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                        @keydown.meta.enter="canSubmit && submit()"
                        @keydown.ctrl.enter="canSubmit && submit()"
                    />
                    <InputError :message="form.errors.prompt" />
                </div>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Size</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            v-for="size in sizes"
                            :key="size.value"
                            :class="
                                cn(
                                    'flex cursor-pointer flex-col items-center gap-2 rounded-lg border p-3 text-sm transition-colors',
                                    form.size === size.value
                                        ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                        : 'border-input hover:bg-accent',
                                )
                            "
                        >
                            <input
                                v-model="form.size"
                                type="radio"
                                name="size"
                                :value="size.value"
                                class="sr-only"
                            />
                            <span
                                :class="
                                    cn(
                                        'rounded-sm border-2 border-current opacity-70',
                                        sizeShapes[size.value],
                                    )
                                "
                            />
                            <span class="font-medium">{{ size.label }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                size.value
                            }}</span>
                        </label>
                    </div>
                    <InputError :message="form.errors.size" />
                </fieldset>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Quality</legend>
                    <div
                        class="inline-grid w-full grid-cols-3 rounded-lg bg-muted p-1"
                    >
                        <label
                            v-for="quality in qualities"
                            :key="quality.value"
                            :class="
                                cn(
                                    'cursor-pointer rounded-md px-3 py-1.5 text-center text-sm font-medium transition-colors',
                                    form.quality === quality.value
                                        ? 'bg-background shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                )
                            "
                        >
                            <input
                                v-model="form.quality"
                                type="radio"
                                name="quality"
                                :value="quality.value"
                                class="sr-only"
                            />
                            {{ quality.label }}
                        </label>
                    </div>
                    <InputError :message="form.errors.quality" />
                </fieldset>

                <ReferenceImageInputs
                    v-model="form.reference_images"
                    :errors="form.errors"
                    :max="MAX_REFERENCE_IMAGES"
                />
            </form>

            <aside class="lg:sticky lg:top-4 lg:self-start">
                <div
                    class="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 bg-card p-5 dark:border-sidebar-border"
                >
                    <div>
                        <p class="text-sm text-muted-foreground">
                            Estimated cost
                        </p>
                        <p
                            class="flex items-center gap-2 text-2xl font-semibold tabular-nums"
                        >
                            <template v-if="estimate">
                                {{ formatUsd(estimate.min_charge_nanos) }} –
                                {{ formatUsd(estimate.max_charge_nanos) }}
                            </template>
                            <span
                                v-else-if="estimator.hasErrors"
                                class="text-base font-normal text-muted-foreground"
                                >Estimate unavailable</span
                            >
                            <Spinner v-else class="size-5" />
                            <Spinner
                                v-if="estimate && estimator.processing"
                                class="size-4 text-muted-foreground"
                            />
                        </p>
                    </div>

                    <p class="flex gap-2 text-xs text-muted-foreground">
                        <Info class="mt-0.5 size-3.5 shrink-0" />
                        <span>
                            Pixelforge only knows the exact token counts once
                            the image is done. We reserve the upper estimate
                            while it runs, then charge the real cost (Pixelforge
                            price + {{ estimate?.markup_percent ?? 25 }}%) and
                            release the rest.
                            <strong class="font-medium text-foreground"
                                >Failed generations are free.</strong
                            >
                        </span>
                    </p>

                    <dl
                        v-if="estimate"
                        class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 border-t border-sidebar-border/70 pt-3 text-xs dark:border-sidebar-border"
                    >
                        <dt class="text-muted-foreground">Prompt tokens</dt>
                        <dd class="text-right tabular-nums">
                            {{ estimate.text_tokens_min }}–{{
                                estimate.text_tokens_max
                            }}
                        </dd>
                        <dt class="text-muted-foreground">
                            Reference image tokens
                        </dt>
                        <dd class="text-right tabular-nums">
                            {{ estimate.image_tokens.toLocaleString() }}
                        </dd>
                        <dt class="text-muted-foreground">Output tokens</dt>
                        <dd class="text-right tabular-nums">
                            {{ estimate.output_tokens.toLocaleString() }}
                        </dd>
                        <dt class="text-muted-foreground">Reasoning tokens</dt>
                        <dd class="text-right tabular-nums">
                            {{
                                estimate.reasoning_tokens_min.toLocaleString()
                            }}–{{
                                estimate.reasoning_tokens_max.toLocaleString()
                            }}
                        </dd>
                    </dl>

                    <div
                        class="flex items-center justify-between rounded-lg bg-muted px-3 py-2 text-sm"
                    >
                        <span
                            class="flex items-center gap-2 text-muted-foreground"
                            ><Wallet class="size-4" /> Balance</span
                        >
                        <span class="font-medium tabular-nums">{{
                            formatUsd(balanceNanos)
                        }}</span>
                    </div>

                    <div
                        v-if="!canAfford"
                        class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                    >
                        This can cost up to
                        {{ formatUsd(estimate?.max_charge_nanos ?? 0) }}, more
                        than your balance.
                        <Link
                            :href="creditsIndex()"
                            class="font-medium underline underline-offset-2"
                            >Buy credits</Link
                        >
                        to continue.
                    </div>
                    <InputError :message="balanceError" />

                    <Button
                        size="lg"
                        :disabled="!canSubmit"
                        class="w-full"
                        @click="submit"
                    >
                        <Spinner v-if="form.processing" />
                        <Sparkles v-else />
                        Generate
                    </Button>
                    <p class="text-center text-xs text-muted-foreground">
                        ⌘/Ctrl + Enter to generate
                    </p>
                </div>
            </aside>
        </div>

        <section v-if="recentGenerations.length" class="flex flex-col gap-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold">Recent</h2>
                <Link
                    :href="historyIndex()"
                    class="text-sm text-muted-foreground hover:text-foreground"
                    >View all →</Link
                >
            </div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <GenerationCard
                    v-for="generation in recentGenerations"
                    :key="generation.id"
                    :generation="generation"
                />
            </div>
        </section>
    </div>
</template>
