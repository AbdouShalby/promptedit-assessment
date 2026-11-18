<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/credits';

defineProps<{
    balance: string;
    creditPack: string;
}>();

const buying = ref(false);

// One key per intended purchase: a double click sends the same key twice and
// the server adds the credit once.
let idempotencyKey = crypto.randomUUID();

function buy() {
    buying.value = true;

    router.post(
        store.url(),
        { idempotency_key: idempotencyKey },
        {
            preserveScroll: true,
            onSuccess: () => (idempotencyKey = crypto.randomUUID()),
            onFinish: () => (buying.value = false),
        },
    );
}
</script>

<template>
    <div class="rounded-lg border px-4 py-3" data-test="credit-balance">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs text-muted-foreground">Your credit</p>
                <p class="text-lg font-semibold tabular-nums">
                    {{ balance }}
                </p>
            </div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="buying"
                @click="buy"
            >
                <Spinner v-if="buying" />
                Buy credits (+{{ creditPack }})
            </Button>
        </div>
        <p class="mt-2 text-xs text-muted-foreground">
            Demo only: no payment is taken. While an image is being made we hold
            its maximum estimate, then charge the actual cost and return the
            rest.
        </p>
    </div>
</template>
