<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Wallet } from '@lucide/vue';
import { computed } from 'vue';
import BuyCreditsDialog from '@/components/BuyCreditsDialog.vue';
import { Button } from '@/components/ui/button';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { usd } from '@/lib/money';

const page = usePage();
const credits = computed(() => page.props.credits);
</script>

<template>
    <template v-if="credits">
        <div
            class="grid gap-2 rounded-lg border bg-background p-3 text-sm group-data-[collapsible=icon]:hidden"
        >
            <div class="flex items-baseline justify-between gap-2">
                <span class="text-muted-foreground">Credits</span>
                <span
                    class="font-semibold tabular-nums"
                    :class="{ 'text-destructive': credits.available < 0 }"
                >
                    {{ usd(credits.available) }}
                </span>
            </div>
            <p v-if="credits.held > 0" class="text-xs text-muted-foreground">
                {{ usd(credits.held) }} on hold for images in progress
            </p>
            <BuyCreditsDialog>
                <Button size="sm" variant="outline" class="w-full">
                    Buy credits
                </Button>
            </BuyCreditsDialog>
        </div>

        <!-- With the sidebar collapsed to icons, keep the credits one click away. -->
        <SidebarMenu class="hidden group-data-[collapsible=icon]:flex">
            <SidebarMenuItem>
                <BuyCreditsDialog>
                    <SidebarMenuButton
                        :tooltip="`Credits: ${usd(credits.available)}`"
                    >
                        <Wallet
                            :class="{
                                'text-destructive': credits.available < 0,
                            }"
                        />
                        <span>Buy credits</span>
                    </SidebarMenuButton>
                </BuyCreditsDialog>
            </SidebarMenuItem>
        </SidebarMenu>
    </template>
</template>
