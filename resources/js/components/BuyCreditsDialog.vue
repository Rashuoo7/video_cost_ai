<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import CreditPurchaseController from '@/actions/App/Http/Controllers/CreditPurchaseController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

const page = usePage();
const open = ref(false);
const form = useForm({ amount: 0 });

function buy(amount: number) {
    form.amount = amount;
    form.post(CreditPurchaseController.store.url(), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>Buy credits</DialogTitle>
                <DialogDescription>
                    Credits are US dollars, spent on your images. This is a
                    demo, so no payment is taken.
                </DialogDescription>
            </DialogHeader>
            <div class="grid grid-cols-3 gap-2">
                <Button
                    v-for="amount in page.props.creditAmounts"
                    :key="amount"
                    variant="outline"
                    :disabled="form.processing"
                    @click="buy(amount)"
                >
                    ${{ amount }}
                </Button>
            </div>
            <InputError :message="form.errors.amount" />
        </DialogContent>
    </Dialog>
</template>
