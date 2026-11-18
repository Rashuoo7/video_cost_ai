<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import GenerationCard from '@/components/GenerationCard.vue';
import GenerationForm from '@/components/GenerationForm.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import type { Generation, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Generate',
                href: dashboard(),
            },
        ],
    },
});

const props = defineProps<{
    generations: Paginated<Generation>;
    sizes: string[];
    qualities: string[];
}>();

// Webhooks update the database; poll for those changes (and the credits they settle) while anything is unfinished.
const { start, stop } = usePoll(
    2000,
    { only: ['generations', 'credits'] },
    { autoStart: false },
);
const hasUnfinished = computed(() =>
    props.generations.data.some(
        (generation) => !['succeeded', 'failed'].includes(generation.status),
    ),
);

watch(hasUnfinished, (unfinished) => (unfinished ? start() : stop()), {
    immediate: true,
});
</script>

<template>
    <Head title="Generate" />

    <div class="mx-auto grid w-full max-w-6xl gap-10 p-4 md:p-6">
        <section class="grid max-w-3xl gap-6">
            <Heading
                title="Generate an image"
                description="Describe what you want, pick a size and quality, and see what it will cost before you start."
            />
            <GenerationForm :sizes="sizes" :qualities="qualities" />
        </section>

        <section class="grid gap-6">
            <Heading
                variant="small"
                title="Your generations"
                description="Statuses update live. You're charged once an image is ready; failed generations are free."
            />

            <p
                v-if="generations.data.length === 0"
                class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                No generations yet. Your images will appear here.
            </p>

            <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <GenerationCard
                    v-for="generation in generations.data"
                    :key="generation.id"
                    :generation="generation"
                />
            </div>

            <nav
                v-if="generations.prev_page_url || generations.next_page_url"
                class="flex justify-between"
                aria-label="Pagination"
            >
                <Button
                    v-if="generations.prev_page_url"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="generations.prev_page_url">Newer</Link>
                </Button>
                <span v-else />
                <Button
                    v-if="generations.next_page_url"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="generations.next_page_url">Older</Link>
                </Button>
            </nav>
        </section>
    </div>
</template>
