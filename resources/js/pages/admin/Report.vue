<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { report } from '@/routes/admin';

// Every amount is an exact 9-decimal string from the server, the same format
// Pixelforge uses, so the two can be compared by eye.

type Row = {
    user: string;
    generations: number;
    charged: string;
    provider_cost: string;
    profit: string;
};

type Day = {
    date: string;
    rows: Row[];
    generations: number;
    charged: string;
    provider_cost: string;
    profit: string;
    usage_cost: string | null;
    matches_usage: boolean | null;
    difference: string | null;
};

type Total = {
    generations: number;
    charged: string;
    provider_cost: string;
    profit: string;
    usage_cost: string | null;
    matches_usage: boolean | null;
};

const props = defineProps<{
    filters: { start_date: string; end_date: string };
    days: Day[];
    total: Total | null;
    error: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Cost report', href: report() }],
    },
});

const range = reactive({ ...props.filters });

function applyRange() {
    router.get(report.url(), range, { preserveState: true });
}

const fieldClass =
    'border-input dark:bg-input/30 rounded-md border bg-transparent px-3 py-1.5 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';
</script>

<template>
    <Head title="Cost report" />

    <div class="space-y-6 p-4">
        <Heading
            title="Cost report"
            description="Per UTC day of completion and per user: what users paid, what Pixelforge charged, and the profit. Provider cost is checked against Pixelforge's own GET /v1/usage."
        />

        <form
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="applyRange"
        >
            <div class="grid gap-1">
                <Label for="start_date">From (UTC)</Label>
                <input
                    id="start_date"
                    v-model="range.start_date"
                    type="date"
                    required
                    :class="fieldClass"
                />
            </div>
            <div class="grid gap-1">
                <Label for="end_date">To (UTC)</Label>
                <input
                    id="end_date"
                    v-model="range.end_date"
                    type="date"
                    required
                    :class="fieldClass"
                />
            </div>
            <Button type="submit" variant="outline">Show</Button>
        </form>

        <Alert v-if="error" variant="destructive">
            <AlertDescription>{{ error }}</AlertDescription>
        </Alert>

        <p
            v-if="days.length === 0 && !error"
            class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            No billed generations in this range.
        </p>

        <div v-if="days.length > 0" class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[46rem] text-sm">
                <thead
                    class="bg-muted/50 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-4 py-2 font-medium">Day / user</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Generations
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Charged to users
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Provider cost
                        </th>
                        <th class="px-4 py-2 text-right font-medium">Profit</th>
                        <th class="px-4 py-2 font-medium">Pixelforge usage</th>
                    </tr>
                </thead>
                <tbody
                    v-for="day in days"
                    :key="day.date"
                    class="border-t tabular-nums"
                    :data-date="day.date"
                >
                    <tr class="bg-muted/20 font-medium">
                        <td class="px-4 py-2">{{ day.date }}</td>
                        <td class="px-4 py-2 text-right">
                            {{ day.generations }}
                        </td>
                        <td class="px-4 py-2 text-right">${{ day.charged }}</td>
                        <td class="px-4 py-2 text-right">
                            ${{ day.provider_cost }}
                        </td>
                        <td class="px-4 py-2 text-right">${{ day.profit }}</td>
                        <td class="px-4 py-2">
                            <template v-if="day.usage_cost !== null">
                                ${{ day.usage_cost }}
                                <Badge v-if="day.matches_usage" class="ml-2">
                                    Match
                                </Badge>
                                <Badge
                                    v-else
                                    variant="destructive"
                                    class="ml-2"
                                >
                                    Mismatch {{ day.difference }}
                                </Badge>
                            </template>
                            <span v-else class="text-muted-foreground">
                                Not available
                            </span>
                        </td>
                    </tr>
                    <tr v-for="row in day.rows" :key="row.user">
                        <td class="px-4 py-1.5 pl-8 text-muted-foreground">
                            {{ row.user }}
                        </td>
                        <td class="px-4 py-1.5 text-right">
                            {{ row.generations }}
                        </td>
                        <td class="px-4 py-1.5 text-right">
                            ${{ row.charged }}
                        </td>
                        <td class="px-4 py-1.5 text-right">
                            ${{ row.provider_cost }}
                        </td>
                        <td class="px-4 py-1.5 text-right">
                            ${{ row.profit }}
                        </td>
                        <td></td>
                    </tr>
                </tbody>
                <tfoot
                    v-if="total"
                    class="border-t-2 font-semibold tabular-nums"
                >
                    <tr>
                        <td class="px-4 py-2">Total</td>
                        <td class="px-4 py-2 text-right">
                            {{ total.generations }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            ${{ total.charged }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            ${{ total.provider_cost }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            ${{ total.profit }}
                        </td>
                        <td class="px-4 py-2">
                            <template v-if="total.usage_cost !== null">
                                ${{ total.usage_cost }}
                                <Badge v-if="total.matches_usage" class="ml-2">
                                    Match
                                </Badge>
                                <Badge
                                    v-else
                                    variant="destructive"
                                    class="ml-2"
                                >
                                    Mismatch
                                </Badge>
                            </template>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</template>
