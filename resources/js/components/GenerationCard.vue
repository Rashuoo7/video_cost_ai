<script setup lang="ts">
import { CircleAlert, ImageOff } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { BadgeVariants } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { usd, usdApprox } from '@/lib/money';
import type { Generation, GenerationStatus } from '@/types';

const props = defineProps<{
    generation: Generation;
}>();

const statuses: Record<
    GenerationStatus,
    { label: string; variant: BadgeVariants['variant']; waiting?: string }
> = {
    pending: {
        label: 'Queued',
        variant: 'secondary',
        waiting: 'Sending to Pixelforge…',
    },
    queued: {
        label: 'Queued',
        variant: 'secondary',
        waiting: 'Waiting in the queue…',
    },
    processing: {
        label: 'Processing',
        variant: 'outline',
        waiting: 'Generating…',
    },
    succeeded: { label: 'Succeeded', variant: 'default' },
    failed: { label: 'Failed', variant: 'destructive' },
};

const status = computed(() => statuses[props.generation.status]);
const imageFailed = ref(false);
// Pixelforge's signed image URLs stop working 24 hours after the generation completes.
const imageExpired = computed(
    () =>
        props.generation.image_url_expires_at !== null &&
        new Date(props.generation.image_url_expires_at) <= new Date(),
);
const showImage = computed(
    () =>
        props.generation.image_url !== null &&
        !imageExpired.value &&
        !imageFailed.value,
);
</script>

<template>
    <Card class="gap-0 overflow-hidden py-0">
        <div
            class="flex aspect-square items-center justify-center bg-muted/50 text-sm text-muted-foreground"
        >
            <a
                v-if="showImage"
                :href="generation.image_url!"
                target="_blank"
                rel="noopener"
                class="size-full"
            >
                <img
                    :src="generation.image_url!"
                    :alt="generation.prompt"
                    loading="lazy"
                    class="size-full object-contain"
                    @error="imageFailed = true"
                />
            </a>
            <div
                v-else-if="status.waiting"
                class="flex flex-col items-center gap-2"
            >
                <Spinner class="size-6" />
                {{ status.waiting }}
            </div>
            <div
                v-else-if="generation.status === 'failed'"
                class="flex flex-col items-center gap-2 text-destructive"
            >
                <CircleAlert class="size-6" />
                Generation failed
            </div>
            <div v-else class="flex flex-col items-center gap-2">
                <ImageOff class="size-6" />
                Image expired
            </div>
        </div>

        <div class="grid gap-3 p-4">
            <div class="flex items-center justify-between gap-2">
                <Badge :variant="status.variant">{{ status.label }}</Badge>
                <time
                    :datetime="generation.created_at"
                    class="text-xs text-muted-foreground"
                >
                    {{ new Date(generation.created_at).toLocaleString() }}
                </time>
            </div>

            <p class="line-clamp-2 text-sm" :title="generation.prompt">
                {{ generation.prompt }}
            </p>

            <p class="text-xs text-muted-foreground">
                {{ generation.size }} ·
                <span class="capitalize">{{ generation.quality }}</span>
                <template v-if="generation.reference_images.length">
                    · {{ generation.reference_images.length }} reference
                    {{
                        generation.reference_images.length === 1
                            ? 'image'
                            : 'images'
                    }}
                </template>
            </p>

            <dl class="grid grid-cols-2 gap-1 text-xs">
                <dt class="text-muted-foreground">Estimated</dt>
                <dd class="text-right tabular-nums">
                    {{ usdApprox(generation.estimated_cost) }}
                </dd>
                <dt class="text-muted-foreground">Charged</dt>
                <dd class="text-right font-medium tabular-nums">
                    <template v-if="generation.cost === null">
                        On completion
                    </template>
                    <template v-else-if="generation.status === 'failed'">
                        {{ usd(0) }} (failed)
                    </template>
                    <template v-else>{{ usd(generation.cost) }}</template>
                </dd>
            </dl>

            <p v-if="generation.error_message" class="text-xs text-destructive">
                {{ generation.error_message }}
            </p>
        </div>
    </Card>
</template>
