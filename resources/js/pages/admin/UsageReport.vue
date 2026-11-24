<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/app/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { exactUsd } from '@/lib/money';
import { usage } from '@/routes/admin';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Usage report', href: usage() }],
    },
});

type DayRow = {
    date: string;
    generations: number;
    credit_spend_nanos: number;
    provider_cost_nanos: number;
    profit_nanos: number;
    pixelforge_generations: number | null;
    pixelforge_cost_nanos: number | null;
    reconciled: boolean | null;
};

type UserRow = {
    day: string;
    user_id: number;
    user_name: string | null;
    user_email: string | null;
    generations: number;
    credit_spend_nanos: number;
    provider_cost_nanos: number;
    profit_nanos: number;
};

const props = defineProps<{
    start: string;
    end: string;
    days: DayRow[];
    users: UserRow[];
    providerError: string | null;
}>();

const start = ref(props.start);
const end = ref(props.end);

function apply(): void {
    router.get(
        usage().url,
        { start: start.value, end: end.value },
        { preserveState: true },
    );
}

const totals = computed(() =>
    props.days.reduce(
        (sum, day) => ({
            generations: sum.generations + day.generations,
            credit: sum.credit + day.credit_spend_nanos,
            provider: sum.provider + day.provider_cost_nanos,
            profit: sum.profit + day.profit_nanos,
            pixelforge: sum.pixelforge + (day.pixelforge_cost_nanos ?? 0),
        }),
        { generations: 0, credit: 0, provider: 0, profit: 0, pixelforge: 0 },
    ),
);

const mismatchedDays = computed(
    () => props.days.filter((day) => day.reconciled === false).length,
);
</script>

<template>
    <Head title="Usage report" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Usage report"
            description="Per UTC day of completion. Provider cost is reconciled against Pixelforge's GET /v1/usage for this API key."
        />

        <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <div class="grid gap-1">
                <Label for="start">From</Label>
                <Input id="start" v-model="start" type="date" />
            </div>
            <div class="grid gap-1">
                <Label for="end">To</Label>
                <Input id="end" v-model="end" type="date" />
            </div>
            <Button type="submit" variant="outline">Apply</Button>
        </form>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div
                v-for="card in [
                    { label: 'Credit spend', value: totals.credit },
                    { label: 'Provider cost (ours)', value: totals.provider },
                    {
                        label: 'Provider cost (Pixelforge)',
                        value: totals.pixelforge,
                    },
                    { label: 'Profit', value: totals.profit },
                ]"
                :key="card.label"
                class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
            >
                <p class="text-sm text-muted-foreground">{{ card.label }}</p>
                <p class="font-mono text-lg font-semibold">
                    {{ exactUsd(card.value) }}
                </p>
            </div>
        </div>

        <div
            v-if="providerError"
            class="flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
            <CircleAlert class="size-4" /> {{ providerError }}
        </div>
        <div
            v-else
            :class="[
                'flex items-center gap-2 rounded-lg border p-3 text-sm',
                mismatchedDays === 0
                    ? 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
                    : 'border-red-300 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-200',
            ]"
        >
            <CircleCheck v-if="mismatchedDays === 0" class="size-4" />
            <CircleAlert v-else class="size-4" />
            {{
                mismatchedDays === 0
                    ? 'Every day matches Pixelforge to the nanodollar.'
                    : `${mismatchedDays} day(s) don't match Pixelforge's usage report.`
            }}
        </div>

        <section class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold">By day</h2>
            <div
                class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Day (UTC)</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Generations
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Credit spend
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Profit
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Pixelforge says
                            </th>
                            <th class="px-4 py-2 font-medium">Match</th>
                        </tr>
                    </thead>
                    <tbody class="font-mono text-xs">
                        <tr
                            v-for="day in days"
                            :key="day.date"
                            class="border-t border-sidebar-border/70 dark:border-sidebar-border"
                        >
                            <td class="px-4 py-2 font-sans text-sm">
                                {{ day.date }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ day.generations }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ exactUsd(day.credit_spend_nanos) }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ exactUsd(day.provider_cost_nanos) }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ exactUsd(day.profit_nanos) }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                <template
                                    v-if="day.pixelforge_cost_nanos !== null"
                                >
                                    {{ exactUsd(day.pixelforge_cost_nanos) }}
                                    <span class="text-muted-foreground"
                                        >({{
                                            day.pixelforge_generations
                                        }})</span
                                    >
                                </template>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-4 py-2">
                                <CircleCheck
                                    v-if="day.reconciled === true"
                                    class="size-4 text-emerald-600"
                                    aria-label="Matches"
                                />
                                <CircleAlert
                                    v-else-if="day.reconciled === false"
                                    class="size-4 text-red-600"
                                    aria-label="Mismatch"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold">By user and day</h2>
            <div
                class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Day (UTC)</th>
                            <th class="px-4 py-2 font-medium">User</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Generations
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Credit spend
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="users.length === 0">
                            <td
                                colspan="6"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No completed generations in this range.
                            </td>
                        </tr>
                        <tr
                            v-for="row in users"
                            :key="`${row.day}-${row.user_id}`"
                            class="border-t border-sidebar-border/70 dark:border-sidebar-border"
                        >
                            <td class="px-4 py-2">{{ row.day }}</td>
                            <td class="px-4 py-2">
                                {{ row.user_name }}
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ row.user_email }}</span
                                >
                            </td>
                            <td class="px-4 py-2 text-right font-mono text-xs">
                                {{ row.generations }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono text-xs">
                                {{ exactUsd(row.credit_spend_nanos) }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono text-xs">
                                {{ exactUsd(row.provider_cost_nanos) }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono text-xs">
                                {{ exactUsd(row.profit_nanos) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
