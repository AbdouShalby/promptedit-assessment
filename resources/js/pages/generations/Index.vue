<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import GenerationForm from '@/components/generations/GenerationForm.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/generations';
import type {
    FormLimits,
    FormOptions,
    Generation,
    Paginated,
} from '@/types/generations';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: index() }],
    },
});

const props = defineProps<{
    generations: Paginated<Generation>;
    options: FormOptions | null;
    limits: FormLimits;
}>();

// Live status: re-fetch the list from our own server every 2 seconds, but
// only while something on the page is still in flight.
const hasInFlight = computed(() =>
    props.generations.data.some((generation) => !generation.is_final),
);

const { start, stop } = usePoll(
    2000,
    { only: ['generations'] },
    { autoStart: false },
);

watch(hasInFlight, (inFlight) => (inFlight ? start() : stop()), {
    immediate: true,
});
</script>

<template>
    <Head title="Generate" />

    <div class="grid gap-8 p-4 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
        <section aria-labelledby="generate-heading">
            <div class="lg:sticky lg:top-4">
                <Heading
                    id="generate-heading"
                    title="Generate an image"
                    description="You pay what the image costs to make, plus 25%."
                />
                <GenerationForm :options="options" :limits="limits" />
            </div>
        </section>

        <section aria-labelledby="history-heading" class="min-w-0">
            <Heading
                id="history-heading"
                title="Your generations"
                description="Newest first. Status updates on its own."
            />

            <p
                v-if="generations.data.length === 0"
                class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                No generations yet. Write a prompt to create your first image.
            </p>

            <div v-else class="space-y-4">
                <GenerationCard
                    v-for="generation in generations.data"
                    :key="generation.uuid"
                    :generation="generation"
                />
            </div>

            <nav
                v-if="generations.last_page > 1"
                class="mt-6 flex items-center justify-between text-sm"
                aria-label="History pages"
            >
                <Button
                    variant="outline"
                    size="sm"
                    as-child
                    :class="{
                        'pointer-events-none opacity-50':
                            !generations.prev_page_url,
                    }"
                >
                    <Link :href="generations.prev_page_url ?? '#'">Newer</Link>
                </Button>
                <span class="text-muted-foreground">
                    Page {{ generations.current_page }} of
                    {{ generations.last_page }}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    as-child
                    :class="{
                        'pointer-events-none opacity-50':
                            !generations.next_page_url,
                    }"
                >
                    <Link :href="generations.next_page_url ?? '#'">Older</Link>
                </Button>
            </nav>
        </section>
    </div>
</template>
