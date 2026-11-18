<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CircleCheck, TriangleAlert } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usd } from '@/lib/money';
import { report } from '@/routes/admin';
import type { Nanodollars, SpendByUser, SpendDay, SpendTotals } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Spend report',
                href: report(),
            },
        ],
    },
});

const props = defineProps<{
    from: string;
    to: string;
    days: SpendDay[];
    users: SpendByUser[];
    totals: SpendTotals & { pixelforge_cost: Nanodollars | null };
}>();

const fromInput = ref(props.from);
const toInput = ref(props.to);

function show() {
    router.get(report.url(), { from: fromInput.value, to: toInput.value });
}
</script>

<template>
    <Head title="Spend report" />

    <div class="mx-auto grid w-full max-w-6xl gap-8 p-4 md:p-6">
        <Heading
            title="Spend report"
            description="What users were charged, what Pixelforge charged us, and the profit. A generation counts on the UTC day it completed, the day Pixelforge bills it, so each day can be checked against Pixelforge's usage report."
        />

        <form class="flex flex-wrap items-end gap-3" @submit.prevent="show">
            <div class="grid gap-1.5">
                <Label for="from">From</Label>
                <Input
                    id="from"
                    v-model="fromInput"
                    type="date"
                    class="w-auto"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="to">To</Label>
                <Input id="to" v-model="toInput" type="date" class="w-auto" />
            </div>
            <Button type="submit" variant="outline">Show</Button>
            <p class="text-xs text-muted-foreground">
                UTC days, up to 92 at a time.
            </p>
        </form>

        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg border p-4">
                <dt class="text-sm text-muted-foreground">Charged to users</dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">
                    {{ usd(totals.charged) }}
                </dd>
                <dd class="text-xs text-muted-foreground">
                    {{ totals.images }}
                    {{ totals.images === 1 ? 'image' : 'images' }}
                </dd>
            </div>
            <div class="rounded-lg border p-4">
                <dt class="text-sm text-muted-foreground">Pixelforge's cost</dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">
                    {{ usd(totals.provider_cost) }}
                </dd>
                <dd class="text-xs text-muted-foreground">from our records</dd>
            </div>
            <div class="rounded-lg border p-4">
                <dt class="text-sm text-muted-foreground">Profit</dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">
                    {{ usd(totals.profit) }}
                </dd>
                <dd class="text-xs text-muted-foreground">the 25% markup</dd>
            </div>
            <div class="rounded-lg border p-4">
                <dt class="text-sm text-muted-foreground">
                    Pixelforge reports
                </dt>
                <template v-if="totals.pixelforge_cost !== null">
                    <dd class="mt-1 text-xl font-semibold tabular-nums">
                        {{ usd(totals.pixelforge_cost) }}
                    </dd>
                    <dd
                        v-if="totals.pixelforge_cost === totals.provider_cost"
                        class="flex items-center gap-1 text-xs text-muted-foreground"
                    >
                        <CircleCheck class="size-3.5 text-green-600" /> matches
                        our records
                    </dd>
                    <dd
                        v-else
                        class="flex items-center gap-1 text-xs text-destructive"
                    >
                        <TriangleAlert class="size-3.5" /> differs by
                        {{
                            usd(
                                Math.abs(
                                    totals.pixelforge_cost -
                                        totals.provider_cost,
                                ),
                            )
                        }}
                    </dd>
                </template>
                <dd v-else class="mt-1 text-sm text-muted-foreground">
                    Couldn't reach Pixelforge's usage report.
                </dd>
            </div>
        </dl>

        <p
            v-if="days.length === 0"
            class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            Nothing was billed between {{ from }} and {{ to }}.
        </p>

        <template v-else>
            <section class="grid gap-3">
                <Heading variant="small" title="By day" />
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead
                            class="border-b bg-muted/40 text-left text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-2 font-medium">Date</th>
                                <th class="px-4 py-2 text-right font-medium">
                                    Images
                                </th>
                                <th class="px-4 py-2 text-right font-medium">
                                    Charged
                                </th>
                                <th class="px-4 py-2 text-right font-medium">
                                    Pixelforge's cost
                                </th>
                                <th class="px-4 py-2 text-right font-medium">
                                    Pixelforge reports
                                </th>
                                <th class="px-4 py-2 text-right font-medium">
                                    Profit
                                </th>
                            </tr>
                        </thead>
                        <tbody class="tabular-nums">
                            <tr
                                v-for="day in days"
                                :key="day.date"
                                class="border-b last:border-0"
                            >
                                <td class="px-4 py-2">{{ day.date }}</td>
                                <td class="px-4 py-2 text-right">
                                    {{ day.images }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    {{ usd(day.charged) }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    {{ usd(day.provider_cost) }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <span
                                        v-if="day.pixelforge_cost === null"
                                        class="text-muted-foreground"
                                        >—</span
                                    >
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1"
                                        :class="{
                                            'text-destructive':
                                                day.pixelforge_cost !==
                                                day.provider_cost,
                                        }"
                                        :title="
                                            day.pixelforge_cost ===
                                            day.provider_cost
                                                ? 'Matches our records'
                                                : 'Differs from our records'
                                        "
                                    >
                                        {{ usd(day.pixelforge_cost) }}
                                        <CircleCheck
                                            v-if="
                                                day.pixelforge_cost ===
                                                day.provider_cost
                                            "
                                            class="size-3.5 text-green-600"
                                        />
                                        <TriangleAlert
                                            v-else
                                            class="size-3.5"
                                        />
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right">
                                    {{ usd(day.profit) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="grid gap-3">
                <Heading variant="small" title="By day and user" />
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead
                            class="border-b bg-muted/40 text-left text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-2 font-medium">Date</th>
                                <th class="px-4 py-2 font-medium">User</th>
                                <th class="px-4 py-2 text-right font-medium">
                                    Images
                                </th>
                                <th class="px-4 py-2 text-right font-medium">
                                    Charged
                                </th>
                                <th class="px-4 py-2 text-right font-medium">
                                    Pixelforge's cost
                                </th>
                                <th class="px-4 py-2 text-right font-medium">
                                    Profit
                                </th>
                            </tr>
                        </thead>
                        <tbody class="tabular-nums">
                            <tr v-if="users.length === 0">
                                <td
                                    colspan="6"
                                    class="px-4 py-6 text-center text-muted-foreground"
                                >
                                    We have no billed generations on record for
                                    these days.
                                </td>
                            </tr>
                            <tr
                                v-for="(row, index) in users"
                                :key="index"
                                class="border-b last:border-0"
                            >
                                <td class="px-4 py-2">{{ row.date }}</td>
                                <td class="px-4 py-2">
                                    <template v-if="row.user">
                                        {{ row.user.name }}
                                        <span
                                            class="block text-xs text-muted-foreground"
                                            >{{ row.user.email }}</span
                                        >
                                    </template>
                                    <span v-else class="text-muted-foreground"
                                        >Deleted account</span
                                    >
                                </td>
                                <td class="px-4 py-2 text-right">
                                    {{ row.images }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    {{ usd(row.charged) }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    {{ usd(row.provider_cost) }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    {{ usd(row.profit) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>
    </div>
</template>
