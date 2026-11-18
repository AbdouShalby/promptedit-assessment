<script setup lang="ts">
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import { estimate as estimateRoute } from '@/routes/generations';
import type { Estimate } from '@/types/generations';

// Shows the price before submitting. The number comes from the server
// (POST /generations/estimate): there is one implementation of the money
// maths, and it is not in the browser.

const props = defineProps<{
    prompt: string;
    size: string;
    quality: string;
    referenceImages: string[];
}>();

const estimate = ref<Estimate | null>(null);
const failed = ref(false);
let latestRequest = 0;

function xsrfToken(): string {
    const cookie = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return cookie ? decodeURIComponent(cookie[1]) : '';
}

const refresh = useDebounceFn(async () => {
    const requestNumber = ++latestRequest;

    if (props.prompt.trim() === '') {
        estimate.value = null;
        failed.value = false;

        return;
    }

    try {
        const response = await fetch(estimateRoute.url(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                prompt: props.prompt,
                size: props.size,
                quality: props.quality,
                // Only the number of images affects the price; half-typed URLs are ignored here.
                reference_images: props.referenceImages
                    .filter((url) => url.trim() !== '')
                    .map(() => 'https://example.com/reference.png'),
            }),
        });

        if (requestNumber !== latestRequest) {
            return; // a newer request is already on its way
        }

        estimate.value = response.ok ? await response.json() : null;
        failed.value = !response.ok;
    } catch {
        if (requestNumber === latestRequest) {
            estimate.value = null;
            failed.value = true;
        }
    }
}, 350);

watch(
    () => [props.prompt, props.size, props.quality, props.referenceImages],
    () => void refresh(),
    { deep: true, immediate: true },
);
</script>

<template>
    <div
        class="rounded-lg border bg-muted/40 px-4 py-3 text-sm"
        aria-live="polite"
        data-test="cost-estimate"
    >
        <template v-if="estimate">
            <p class="font-medium">
                Estimated cost
                <span class="tabular-nums" :title="`$${estimate.min_exact}`">{{
                    estimate.min
                }}</span>
                to
                <span class="tabular-nums" :title="`$${estimate.max_exact}`">{{
                    estimate.max
                }}</span>
            </p>
            <p class="mt-1 text-muted-foreground">
                An estimate: part of the cost is only known when the image is
                finished. You pay the actual cost then. Failed generations are
                free.
            </p>
        </template>
        <p v-else-if="failed" class="text-red-600 dark:text-red-500">
            Could not calculate a price for this input. Check the fields above.
        </p>
        <p v-else class="text-muted-foreground">
            Write a prompt to see what it will cost.
        </p>
    </div>
</template>
