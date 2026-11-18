<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import { computed } from 'vue';
import CostEstimate from '@/components/generations/CostEstimate.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/generations';
import type { FormLimits, FormOptions } from '@/types/generations';

const props = defineProps<{
    options: FormOptions | null;
    limits: FormLimits;
}>();

// The uuid is created once per form and sent with the request. The server
// uses it as the generation's id, so a double click cannot create two.
const form = useForm({
    uuid: crypto.randomUUID(),
    prompt: '',
    size: props.options?.sizes[0] ?? '',
    quality: props.options?.qualities.includes('medium')
        ? 'medium'
        : (props.options?.qualities[0] ?? ''),
    reference_images: [] as string[],
});

// Errors that do not belong to one field ("limit", "pricing", "uuid",
// "reference_images.0", ...) are read through this untyped view.
const errors = computed(() => form.errors as Record<string, string>);

const promptLength = computed(() => [...form.prompt].length);
const canAddReference = computed(
    () => form.reference_images.length < props.limits.reference_images,
);

const fieldClass =
    'border-input dark:bg-input/30 w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 md:text-sm';

function addReference() {
    form.reference_images.push('');
}

function removeReference(index: number) {
    form.reference_images.splice(index, 1);
}

function submit() {
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.uuid = crypto.randomUUID();
        },
    });
}
</script>

<template>
    <form class="space-y-5" @submit.prevent="submit">
        <Alert v-if="!options" variant="destructive">
            <AlertTitle>Pricing is unavailable</AlertTitle>
            <AlertDescription>
                We cannot reach Pixelforge to quote a price, so generating is
                paused. Reload the page in a minute.
            </AlertDescription>
        </Alert>

        <div class="grid gap-2">
            <div class="flex items-baseline justify-between">
                <Label for="prompt">Prompt</Label>
                <span
                    class="text-xs tabular-nums"
                    :class="
                        promptLength > limits.prompt
                            ? 'text-red-600 dark:text-red-500'
                            : 'text-muted-foreground'
                    "
                >
                    {{ promptLength }} / {{ limits.prompt }}
                </span>
            </div>
            <textarea
                id="prompt"
                v-model="form.prompt"
                rows="4"
                required
                :class="fieldClass"
                placeholder="A red fox reading a newspaper in a Cairo cafe"
            />
            <InputError :message="errors.prompt" />
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div class="grid gap-2">
                <Label for="size">Size</Label>
                <select
                    id="size"
                    v-model="form.size"
                    :class="fieldClass"
                    :disabled="!options"
                >
                    <option
                        v-for="size in options?.sizes ?? []"
                        :key="size"
                        :value="size"
                    >
                        {{ size.replace('x', ' × ') }}
                    </option>
                </select>
                <InputError :message="errors.size" />
            </div>

            <div class="grid gap-2">
                <Label for="quality">Quality</Label>
                <select
                    id="quality"
                    v-model="form.quality"
                    class="capitalize"
                    :class="fieldClass"
                    :disabled="!options"
                >
                    <option
                        v-for="quality in options?.qualities ?? []"
                        :key="quality"
                        :value="quality"
                    >
                        {{ quality }}
                    </option>
                </select>
                <InputError :message="errors.quality" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label>
                Reference images
                <span class="font-normal text-muted-foreground">
                    (optional, up to {{ limits.reference_images }})
                </span>
            </Label>

            <div
                v-for="(_, index) in form.reference_images"
                :key="index"
                class="grid gap-1"
            >
                <div class="flex gap-2">
                    <input
                        v-model="form.reference_images[index]"
                        type="url"
                        :class="fieldClass"
                        placeholder="https://example.com/image.png"
                        :aria-label="'Reference image URL ' + (index + 1)"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        :aria-label="'Remove reference image ' + (index + 1)"
                        @click="removeReference(index)"
                    >
                        <X />
                    </Button>
                </div>
                <InputError :message="errors['reference_images.' + index]" />
            </div>

            <div>
                <Button
                    v-if="canAddReference"
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addReference"
                >
                    <Plus /> Add image URL
                </Button>
            </div>
            <InputError :message="errors.reference_images" />
        </div>

        <CostEstimate
            v-if="options"
            :prompt="form.prompt"
            :size="form.size"
            :quality="form.quality"
            :reference-images="form.reference_images"
        />

        <InputError :message="errors.limit" />
        <InputError :message="errors.pricing" />
        <InputError :message="errors.uuid" />

        <Button
            type="submit"
            class="w-full"
            :disabled="form.processing || !options"
        >
            <Spinner v-if="form.processing" />
            Generate image
        </Button>
    </form>
</template>
