<script setup>
defineProps({
    accounts: { type: Array, default: () => [] },
});

const DOT = {
    active: 'bg-[var(--el-color-success)]',
    invalid_credentials: 'bg-[var(--el-color-danger)]',
    disabled: 'bg-[var(--el-border-color-darker)]',
};
</script>

<template>
    <div class="flex flex-none flex-col border-t border-[var(--el-border-color-light)] px-[18px] pb-4 pt-5">
        <div class="mb-2 flex items-center justify-between">
            <p class="text-xs font-semibold uppercase tracking-[0.06em] text-[var(--el-text-color-placeholder)]">
                {{ $t('library.explorer.marketplaces') }}
            </p>
            <!-- TODO: link to the marketplaces page once it exists. -->
            <el-tooltip :content="$t('library.nav.soon', { section: $t('library.nav.marketplaces') })" placement="top">
                <span class="cursor-not-allowed text-[13px] text-[var(--el-color-primary)]">{{ $t('library.explorer.manage') }}</span>
            </el-tooltip>
        </div>

        <p v-if="!accounts.length" class="text-[13px] text-[var(--el-text-color-secondary)]">
            {{ $t('library.explorer.no_accounts') }}
        </p>

        <div v-for="account in accounts" :key="account.id" class="flex h-[26px] items-center gap-2.5 text-[13px]">
            <span class="size-1.5 flex-none rounded-full" :class="DOT[account.status]" />
            <span class="min-w-0 flex-1 truncate">{{ account.label || account.marketplace.name }}</span>
            <span
                :class="account.status === 'invalid_credentials'
                    ? 'text-[var(--el-color-danger)]'
                    : 'text-[var(--el-text-color-placeholder)]'"
            >
                {{ $t(`library.explorer.account_status.${account.status}`) }}
            </span>
        </div>
    </div>
</template>
