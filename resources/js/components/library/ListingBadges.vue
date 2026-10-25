<script setup>
import { computed } from 'vue';
import { trans } from 'laravel-vue-i18n';
import MarketplaceLogo from '@/components/settings/MarketplaceLogo.vue';
import { listingState } from '@/utils/publishing';

const props = defineProps({
    // asset.listings
    listings: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
});

const badges = computed(() => props.listings
    .map((listing) => ({ listing, account: props.accounts.find((account) => account.id === listing.account_id) }))
    .filter(({ account }) => account)
    .map(({ listing, account }) => {
        const state = listingState(listing);
        const name = account.label || account.marketplace.name;

        return { id: listing.id, account, state, title: `${name}: ${trans(`library.publishing.status.${state}`)}` };
    }));
</script>

<template>
    <span v-if="badges.length" class="listing-badges">
        <!-- A logo per marketplace the work went to, ringed with the colour of its state. -->
        <el-tooltip v-for="badge in badges" :key="badge.id" :content="badge.title" placement="top" :show-after="300">
            <span class="listing-badges__item" :class="`is-${badge.state}`" :aria-label="badge.title">
                <MarketplaceLogo :name="badge.account.marketplace.name" :logo-url="badge.account.marketplace.logo_url" :size="18" />
            </span>
        </el-tooltip>
    </span>
</template>

<style scoped lang="scss">
.listing-badges {
    position: absolute;
    right: 8px;
    bottom: 8px;
    display: flex;
    gap: 4px;
}

.listing-badges__item {
    display: flex;
    padding: 1px;
    background: var(--el-bg-color);
    border: 2px solid var(--el-border-color);
    border-radius: 7px;

    :deep(.marketplace-logo) {
        font-size: 8px;
        border-radius: 4px;
    }

    &.is-queued,
    &.is-sending {
        border-color: var(--el-color-primary-light-3);
    }

    &.is-sent {
        border-color: var(--el-color-success);
    }

    &.is-outdated {
        border-color: var(--el-color-warning);
    }

    &.is-failed {
        border-color: var(--el-color-danger);
    }

    &.is-cancelled {
        opacity: 0.6;
    }
}
</style>
