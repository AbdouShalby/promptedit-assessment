<script setup lang="ts">
import { ImageOff } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import type { Generation } from '@/types/generations';

const props = defineProps<{
    generation: Generation;
}>();

// The signed URL can also expire while the page is open: fall back to the same placeholder.
const imageBroken = ref(false);

const imageExpired = computed(
    () => props.generation.image_expired || imageBroken.value,
);

const badgeVariant = computed(() => {
    switch (props.generation.status) {
        case 'succeeded':
            return 'default';
        case 'failed':
            return 'destructive';
        default:
            return 'secondary';
    }
});

const createdAt = computed(() =>
    new Date(props.generation.created_at).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }),
);
</script>

<template>
    <article
        class="flex flex-col gap-4 rounded-xl border bg-card p-4 text-card-foreground shadow-sm sm:flex-row"
        :data-status="generation.status"
    >
        <div
            class="flex aspect-square w-full shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted text-center text-xs text-muted-foreground sm:w-40"
        >
            <a
                v-if="generation.image_url && !imageExpired"
                :href="generation.image_url"
                target="_blank"
                rel="noopener noreferrer"
                class="size-full"
            >
                <img
                    :src="generation.image_url"
                    :alt="generation.prompt"
                    class="size-full object-contain"
                    loading="lazy"
                    @error="imageBroken = true"
                />
            </a>
            <div v-else-if="imageExpired" class="space-y-1 p-3">
                <ImageOff class="mx-auto size-5" />
                <p>Image expired</p>
                <p>Pixelforge keeps images for 24 hours.</p>
            </div>
            <div v-else-if="!generation.is_final" class="space-y-2 p-3">
                <Spinner class="mx-auto size-5" />
                <p>
                    {{
                        generation.status === 'processing'
                            ? 'Generating…'
                            : 'In the queue…'
                    }}
                </p>
            </div>
            <p v-else class="p-3">No image</p>
        </div>

        <div class="min-w-0 flex-1 space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <Badge :variant="badgeVariant" data-test="status-badge">
                    <Spinner v-if="generation.status === 'processing'" />
                    {{ generation.status_label }}
                </Badge>
                <span
                    v-if="generation.waiting_for_slot"
                    class="text-xs text-muted-foreground"
                >
                    Waiting for a free slot
                </span>
                <time
                    class="ml-auto text-xs text-muted-foreground"
                    :datetime="generation.created_at"
                >
                    {{ createdAt }}
                </time>
            </div>

            <p class="line-clamp-3 text-sm break-words">
                {{ generation.prompt }}
            </p>

            <p class="text-xs text-muted-foreground">
                {{ generation.size.replace('x', ' × ') }} ·
                <span class="capitalize">{{ generation.quality }}</span>
                quality
                <template v-if="generation.reference_image_count > 0">
                    · {{ generation.reference_image_count }} reference
                    {{
                        generation.reference_image_count === 1
                            ? 'image'
                            : 'images'
                    }}
                </template>
            </p>

            <dl class="grid grid-cols-2 gap-2 pt-1 text-sm">
                <div>
                    <dt class="text-xs text-muted-foreground">Estimated</dt>
                    <dd
                        class="tabular-nums"
                        :title="`Up to $${generation.estimated_max_exact}`"
                    >
                        {{ generation.estimated_min }} –
                        {{ generation.estimated_max }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Actual cost</dt>
                    <dd
                        v-if="
                            generation.status === 'succeeded' &&
                            generation.charged
                        "
                        class="font-medium tabular-nums"
                        :title="`$${generation.charged_exact}`"
                        data-test="charged"
                    >
                        {{ generation.charged }}
                    </dd>
                    <!-- Only when the cost is known to be zero; an unconfirmed submission shows a dash. -->
                    <dd
                        v-else-if="
                            generation.status === 'failed' && generation.charged
                        "
                        data-test="charged"
                    >
                        Not charged
                    </dd>
                    <dd v-else class="text-muted-foreground">
                        {{ generation.is_final ? '—' : 'When finished' }}
                    </dd>
                </div>
            </dl>

            <p
                v-if="generation.error_message"
                class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300"
                role="status"
            >
                {{ generation.error_message }}
            </p>
        </div>
    </article>
</template>
