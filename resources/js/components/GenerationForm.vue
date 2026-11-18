<script setup lang="ts">
import { useForm, useHttp, usePage } from '@inertiajs/vue3';
import { Plus, Sparkles, X } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, ref } from 'vue';
import GenerationController from '@/actions/App/Http/Controllers/GenerationController';
import BuyCreditsDialog from '@/components/BuyCreditsDialog.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { usd, usdApprox } from '@/lib/money';
import type { Estimate } from '@/types';

defineProps<{
    sizes: string[];
    qualities: string[];
}>();

const MAX_PROMPT_CHARACTERS = 4000;
const MAX_REFERENCE_IMAGES = 4;
// Kept out of the template: vue-tsc mis-parses "//" in a component's attributes.
const REFERENCE_IMAGE_EXAMPLE = 'https://example.com/style.png';

const sizeLabels: Record<string, string> = {
    '1024x1024': 'Square · 1024 × 1024',
    '1536x1024': 'Landscape · 1536 × 1024',
    '1024x1536': 'Portrait · 1024 × 1536',
};

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

// Pixelforge counts Unicode characters, as PHP's mb_strlen does; a string's .length would count an emoji as two.
const characters = computed(() => [...form.prompt].length);
const referenceImages = computed(() =>
    form.reference_images.filter((url) => url.trim() !== ''),
);

// The server prices the estimate, so the figure shown here is the one recorded with the generation.
const estimate = useHttp<
    {
        characters: number;
        size: string;
        quality: string;
        reference_images: number;
    },
    Estimate
>({ characters: 0, size: '', quality: '', reference_images: 0 });
const estimateFailed = ref(false);

const page = usePage();
// The most an image could cost is held from the user's credits until it's done, so that much must be available.
const shortOfCredits = computed(
    () =>
        estimate.response !== null &&
        page.props.credits !== null &&
        page.props.credits.available < estimate.response.max,
);

function fetchEstimate() {
    Object.assign(estimate, {
        characters: Math.min(characters.value, MAX_PROMPT_CHARACTERS),
        size: form.size,
        quality: form.quality,
        reference_images: referenceImages.value.length,
    });
    estimate.cancel();
    estimate
        .get(GenerationController.estimate.url(), {
            onSuccess: () => (estimateFailed.value = false),
            // A 401 means the session expired: reloading sends the user to log in.
            onHttpException: (response) =>
                void (response.status === 401
                    ? window.location.reload()
                    : (estimateFailed.value = true)),
            onNetworkError: () => void (estimateFailed.value = true),
        })
        .catch(() => {}); // Failures are handled above; cancelled requests land here too.
}

watchDebounced(
    () => [
        characters.value,
        form.size,
        form.quality,
        referenceImages.value.length,
    ],
    fetchEstimate,
    { debounce: 250, immediate: true },
);

function addReferenceImage() {
    form.reference_images.push('');
}

function referenceImageError(index: number) {
    return form.errors[`reference_images.${index}`];
}

function removeReferenceImage(index: number) {
    form.reference_images.splice(index, 1);
}

function submit() {
    // Drop blank rows from the form itself, so the server's reference_images.N errors line up with the inputs.
    form.reference_images = [...referenceImages.value];
    form.post(GenerationController.store.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset('prompt', 'reference_images'),
    });
}
</script>

<template>
    <form class="grid gap-6" @submit.prevent="submit">
        <div class="grid gap-2">
            <div class="flex items-baseline justify-between">
                <Label for="prompt">Prompt</Label>
                <span
                    class="text-xs text-muted-foreground tabular-nums"
                    :class="{
                        'text-destructive': characters > MAX_PROMPT_CHARACTERS,
                    }"
                >
                    {{ characters.toLocaleString() }} /
                    {{ MAX_PROMPT_CHARACTERS.toLocaleString() }}
                </span>
            </div>
            <textarea
                id="prompt"
                v-model="form.prompt"
                rows="3"
                required
                placeholder="A lighthouse on a cliff at dawn, oil painting"
                :aria-invalid="!!form.errors.prompt"
                class="min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
            ></textarea>
            <InputError :message="form.errors.prompt" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="size">Size</Label>
                <Select v-model="form.size">
                    <SelectTrigger id="size" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="size in sizes"
                            :key="size"
                            :value="size"
                        >
                            {{ sizeLabels[size] ?? size }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.size" />
            </div>

            <div class="grid gap-2">
                <Label for="quality">Quality</Label>
                <Select v-model="form.quality">
                    <SelectTrigger id="quality" class="w-full capitalize">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="quality in qualities"
                            :key="quality"
                            :value="quality"
                            class="capitalize"
                        >
                            {{ quality }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.quality" />
            </div>
        </div>

        <fieldset class="grid gap-2">
            <legend class="mb-2 text-sm font-medium">
                Reference images
                <span class="font-normal text-muted-foreground">
                    (optional, up to {{ MAX_REFERENCE_IMAGES }})
                </span>
            </legend>
            <div
                v-for="(_, index) in form.reference_images"
                :key="index"
                class="grid gap-1"
            >
                <div class="flex gap-2">
                    <Input
                        v-model="form.reference_images[index]"
                        type="url"
                        :placeholder="REFERENCE_IMAGE_EXAMPLE"
                        :aria-label="`Reference image ${index + 1}`"
                        :aria-invalid="!!referenceImageError(index)"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Remove reference image ${index + 1}`"
                        @click="removeReferenceImage(index)"
                    >
                        <X />
                    </Button>
                </div>
                <InputError :message="referenceImageError(index)" />
            </div>
            <InputError :message="form.errors.reference_images" />
            <Button
                v-if="form.reference_images.length < MAX_REFERENCE_IMAGES"
                type="button"
                variant="outline"
                size="sm"
                class="w-fit"
                @click="addReferenceImage"
            >
                <Plus /> Add reference image
            </Button>
        </fieldset>

        <div
            class="flex flex-col gap-4 rounded-lg border bg-muted/40 p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="text-sm" aria-live="polite">
                <p v-if="estimateFailed" class="text-destructive">
                    Prices are unavailable right now.
                    <button
                        type="button"
                        class="font-medium underline underline-offset-4"
                        @click="fetchEstimate"
                    >
                        Try again
                    </button>
                </p>
                <template v-else-if="estimate.response">
                    <p>
                        Estimated cost
                        <span class="font-semibold tabular-nums">
                            ≈ {{ usdApprox(estimate.response.expected) }}
                        </span>
                        <span class="text-muted-foreground tabular-nums">
                            (between {{ usdApprox(estimate.response.min) }} and
                            {{ usdApprox(estimate.response.max) }})
                        </span>
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Pixelforge's cost plus 25%. The final price depends on
                        how much the model thinks, so it's settled when the
                        image is ready. Failed generations are free.
                    </p>
                    <p v-if="shortOfCredits" class="mt-2 text-destructive">
                        This image could cost up to
                        {{ usdApprox(estimate.response.max) }}, and you have
                        {{ usd(page.props.credits?.available ?? 0) }} available.
                        <BuyCreditsDialog>
                            <button
                                type="button"
                                class="font-medium underline underline-offset-4"
                            >
                                Buy credits
                            </button>
                        </BuyCreditsDialog>
                    </p>
                </template>
                <p v-else class="text-muted-foreground">
                    Working out the price…
                </p>
                <InputError class="mt-2" :message="page.props.errors.credits" />
            </div>

            <Button
                type="submit"
                class="shrink-0"
                :disabled="form.processing || estimateFailed || shortOfCredits"
            >
                <Spinner v-if="form.processing" />
                <Sparkles v-else />
                Generate
            </Button>
        </div>
    </form>
</template>
