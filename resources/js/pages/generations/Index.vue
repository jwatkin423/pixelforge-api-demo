<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { watch } from 'vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import Heading from '@/components/app/Heading.vue';
import { Button } from '@/components/ui/button';
import { create, index } from '@/routes/generations';
import type { Generation, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'History', href: index() }],
    },
});

const props = defineProps<{
    generations: Paginated<Generation>;
    hasActiveGenerations: boolean;
}>();

const poll = usePoll(
    2500,
    { only: ['generations', 'hasActiveGenerations', 'auth'] },
    { autoStart: props.hasActiveGenerations },
);

watch(
    () => props.hasActiveGenerations,
    (active) => (active ? poll.start() : poll.stop()),
);
</script>

<template>
    <Head title="History" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="History"
                description="Every generation, what it was estimated at, and what you were actually charged."
            />
            <Button as-child>
                <Link :href="create()"><Sparkles /> New generation</Link>
            </Button>
        </div>

        <p
            v-if="hasActiveGenerations"
            class="-mt-2 flex items-center gap-2 text-sm text-muted-foreground"
        >
            <span class="relative flex size-2">
                <span
                    class="absolute inline-flex size-full animate-ping rounded-full bg-sky-400 opacity-75"
                />
                <span
                    class="relative inline-flex size-2 rounded-full bg-sky-500"
                />
            </span>
            Updating live while generations are in progress.
        </p>

        <div
            v-if="generations.data.length === 0"
            class="flex flex-col items-center gap-4 rounded-xl border border-dashed border-sidebar-border p-12 text-center"
        >
            <p class="text-muted-foreground">No generations yet.</p>
            <Button as-child>
                <Link :href="create()"><Sparkles /> Create your first</Link>
            </Button>
        </div>

        <div
            v-else
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
        >
            <GenerationCard
                v-for="generation in generations.data"
                :key="generation.id"
                :generation="generation"
            />
        </div>

        <nav
            v-if="generations.last_page > 1"
            class="flex items-center justify-between text-sm"
        >
            <Button
                variant="outline"
                size="sm"
                :disabled="!generations.prev_page_url"
                as-child
            >
                <Link :href="generations.prev_page_url ?? '#'" preserve-scroll
                    >Newer</Link
                >
            </Button>
            <span class="text-muted-foreground"
                >Page {{ generations.current_page }} of
                {{ generations.last_page }}</span
            >
            <Button
                variant="outline"
                size="sm"
                :disabled="!generations.next_page_url"
                as-child
            >
                <Link :href="generations.next_page_url ?? '#'" preserve-scroll
                    >Older</Link
                >
            </Button>
        </nav>
    </div>
</template>
