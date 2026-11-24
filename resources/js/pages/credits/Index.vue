<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { CreditCard } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/app/Heading.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { exactUsd, formatUsd } from '@/lib/money';
import { cn } from '@/lib/utils';
import { index, store } from '@/routes/credits';
import type { Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credits', href: index() }],
    },
});

type Transaction = {
    id: number;
    type: 'purchase' | 'hold' | 'hold_release' | 'charge';
    description: string;
    amount_nanos: number;
    balance_after_nanos: number;
    prompt: string | null;
    created_at: string;
};

defineProps<{
    packs: number[];
    heldNanos: number;
    transactions: Paginated<Transaction>;
}>();

const page = usePage();
const balanceNanos = computed(() => page.props.auth.user.balance_nanos);
const buying = ref<number | null>(null);

function buy(amount: number): void {
    router.post(
        store().url,
        { amount },
        {
            preserveScroll: true,
            onStart: () => (buying.value = amount),
            onFinish: () => (buying.value = null),
        },
    );
}

const typeLabels: Record<Transaction['type'], string> = {
    purchase: 'Purchase',
    hold: 'Hold',
    hold_release: 'Hold released',
    charge: 'Charge',
};
</script>

<template>
    <Head title="Credits" />

    <div class="flex flex-1 flex-col gap-8 p-4 md:p-6">
        <Heading
            title="Credits"
            description="Credits are US dollars. Generations are paid from your balance."
        />

        <div class="grid gap-4 md:grid-cols-[minmax(0,20rem)_1fr]">
            <div
                class="flex flex-col gap-1 rounded-xl border border-sidebar-border/70 bg-card p-5 dark:border-sidebar-border"
            >
                <p class="text-sm text-muted-foreground">Available balance</p>
                <p
                    class="text-3xl font-semibold tabular-nums"
                    :title="exactUsd(balanceNanos)"
                >
                    {{ formatUsd(balanceNanos) }}
                </p>
                <p v-if="heldNanos > 0" class="text-xs text-muted-foreground">
                    {{ formatUsd(heldNanos) }} is reserved for generations in
                    progress and will be partly returned when they finish.
                </p>
            </div>

            <div
                class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-5 dark:border-sidebar-border"
            >
                <p class="text-sm font-medium">Buy credits</p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="pack in packs"
                        :key="pack"
                        variant="outline"
                        :disabled="buying !== null"
                        @click="buy(pack)"
                    >
                        <Spinner v-if="buying === pack" />
                        <CreditCard v-else />
                        Add ${{ pack }}
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground">
                    This is a demo: no payment is taken.
                </p>
            </div>
        </div>

        <section class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold">Activity</h2>
            <div
                class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">When</th>
                            <th class="px-4 py-2 font-medium">Type</th>
                            <th class="px-4 py-2 font-medium">Details</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Amount
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Balance
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="transactions.data.length === 0">
                            <td
                                colspan="5"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No activity yet. Add some credits to get
                                started.
                            </td>
                        </tr>
                        <tr
                            v-for="transaction in transactions.data"
                            :key="transaction.id"
                            class="border-t border-sidebar-border/70 dark:border-sidebar-border"
                        >
                            <td
                                class="px-4 py-2 whitespace-nowrap text-muted-foreground"
                            >
                                {{
                                    new Date(
                                        transaction.created_at,
                                    ).toLocaleString(undefined, {
                                        dateStyle: 'medium',
                                        timeStyle: 'short',
                                    })
                                }}
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                {{ typeLabels[transaction.type] }}
                            </td>
                            <td class="max-w-xs truncate px-4 py-2">
                                {{
                                    transaction.prompt ??
                                    transaction.description
                                }}
                            </td>
                            <td
                                :class="
                                    cn(
                                        'px-4 py-2 text-right tabular-nums',
                                        transaction.amount_nanos > 0 &&
                                            'text-emerald-700 dark:text-emerald-400',
                                    )
                                "
                                :title="exactUsd(transaction.amount_nanos)"
                            >
                                {{ transaction.amount_nanos > 0 ? '+' : ''
                                }}{{ formatUsd(transaction.amount_nanos) }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ formatUsd(transaction.balance_after_nanos) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
