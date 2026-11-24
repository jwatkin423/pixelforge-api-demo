<script setup lang="ts">
import { ImagePlus, Plus, X } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/input/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const props = withDefaults(
    defineProps<{
        errors?: Record<string, string | undefined>;
        max?: number;
    }>(),
    {
        errors: () => ({}),
        max: 4,
    },
);

const PLACEHOLDER_URL = 'https://example.com/style.png';

const urls = defineModel<string[]>({ required: true });

const canAdd = computed(() => urls.value.length < props.max);

function add(): void {
    if (canAdd.value) {
        urls.value = [...urls.value, ''];
    }
}

function update(position: number, value: string | number): void {
    urls.value = urls.value.map((url, index) =>
        index === position ? String(value) : url,
    );
}

function remove(position: number): void {
    urls.value = urls.value.filter((_, index) => index !== position);
}
</script>

<template>
    <div class="grid gap-2">
        <div class="flex items-center justify-between">
            <span class="text-sm font-medium">
                Reference images
                <span class="font-normal text-muted-foreground">
                    (optional, up to {{ max }})
                </span>
            </span>
            <Button
                v-if="canAdd"
                type="button"
                variant="ghost"
                size="sm"
                @click="add"
            >
                <Plus /> Add URL
            </Button>
        </div>

        <p
            v-if="urls.length === 0"
            class="flex items-center gap-2 rounded-lg border border-dashed border-input p-3 text-sm text-muted-foreground"
        >
            <ImagePlus class="size-4" />
            Link to images that guide the style or subject. Each adds 765 input
            tokens.
        </p>

        <div v-for="(item, index) in urls" :key="index" class="grid gap-1">
            <div class="flex gap-2">
                <Input
                    :model-value="item"
                    type="url"
                    :placeholder="PLACEHOLDER_URL"
                    :aria-label="'Reference image ' + (index + 1)"
                    @update:model-value="update(index, $event)"
                />
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    :aria-label="'Remove reference image ' + (index + 1)"
                    @click="remove(index)"
                >
                    <X />
                </Button>
            </div>
            <InputError :message="errors?.['reference_images.' + index]" />
        </div>

        <InputError :message="errors?.reference_images" />
    </div>
</template>
