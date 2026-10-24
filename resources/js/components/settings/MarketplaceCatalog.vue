<script setup>
import { trans } from 'laravel-vue-i18n';
import MarketplaceLogo from '@/components/settings/MarketplaceLogo.vue';

defineProps({
    // Connectable marketplaces without an account yet
    marketplaces: { type: Array, required: true },
});

const emit = defineEmits(['connect']);

const subline = (marketplace) => [
    marketplace.upload_protocol.toUpperCase(),
    marketplace.supported_types.map((type) => trans(`library.types.${type}`)).join(', '),
].join(' · ');
</script>

<template>
    <div class="grid grid-cols-[repeat(auto-fill,minmax(240px,1fr))] gap-2.5">
        <div
            v-for="marketplace in marketplaces"
            :key="marketplace.code"
            class="flex items-center gap-3 rounded-[10px] border border-[var(--el-border-color-light)] bg-[var(--el-bg-color)] py-3 pl-3.5 pr-3"
        >
            <MarketplaceLogo :name="marketplace.name" :logo-url="marketplace.logo_url" />
            <div class="min-w-0 flex-1">
                <p class="truncate font-semibold">{{ marketplace.name }}</p>
                <p class="truncate text-xs leading-[18px] text-[var(--el-text-color-secondary)]">{{ subline(marketplace) }}</p>
            </div>
            <el-button size="small" @click="emit('connect', marketplace.code)">{{ $t('settings.marketplaces.connect') }}</el-button>
        </div>
    </div>
</template>
