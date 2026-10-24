<script setup>
import { getActiveLanguage, trans } from 'laravel-vue-i18n';
import { Trash2 } from 'lucide-vue-next';
import MarketplaceLogo from '@/components/settings/MarketplaceLogo.vue';
import { connectionState as state } from '@/utils/marketplaceConnection';
import { formatRelative } from '@/utils/relativeTime';

defineProps({
    accounts: { type: Array, required: true },
    // Ids of accounts with a check in progress
    checking: { type: Object, default: () => new Set() },
});

const emit = defineEmits(['check', 'toggle', 'edit', 'remove']);

const DOT = {
    active: 'bg-[var(--el-color-success)]',
    unreachable: 'bg-[var(--el-color-warning)]',
    check_failed: 'bg-[var(--el-color-warning)]',
    invalid_credentials: 'bg-[var(--el-color-danger)]',
    disabled: 'bg-[var(--el-border-color-darker)]',
    checking: 'bg-[var(--el-text-color-placeholder)]',
};

const TEXT = {
    unreachable: 'text-[var(--el-color-warning-dark-2)]',
    check_failed: 'text-[var(--el-color-warning-dark-2)]',
    invalid_credentials: 'text-[var(--el-color-danger)]',
    disabled: 'text-[var(--el-text-color-secondary)]',
    checking: 'text-[var(--el-text-color-secondary)]',
};

function detail(account, current) {
    if (current === 'checking') {
        return trans('settings.marketplaces.status.signing_in', { host: account.host });
    }

    if (current === 'disabled') {
        return trans('settings.marketplaces.status.skipped');
    }

    return account.last_verified_at
        ? trans('settings.marketplaces.status.checked', { time: formatRelative(account.last_verified_at, getActiveLanguage()) })
        : trans('settings.marketplaces.status.never_checked');
}

const subline = (account) => [
    account.label !== account.marketplace.name ? account.marketplace.name : null,
    account.marketplace.upload_protocol.toUpperCase(),
    account.login,
].filter(Boolean).join(' · ');
</script>

<template>
    <div class="overflow-hidden rounded-[10px] border border-[var(--el-border-color-light)] bg-[var(--el-bg-color)]">
        <div
            v-for="account in accounts"
            :key="account.id"
            class="grid grid-cols-[36px_minmax(0,1fr)_230px_auto] items-center gap-3.5 border-t border-[var(--el-border-color-lighter)] px-4 py-3.5 first:border-t-0"
        >
            <MarketplaceLogo :name="account.marketplace.name" :logo-url="account.marketplace.logo_url" :class="{ 'opacity-55': account.status === 'disabled' }" />

            <div class="min-w-0" :class="{ 'opacity-55': account.status === 'disabled' }">
                <div class="flex min-w-0 items-center gap-2">
                    <span class="truncate font-semibold">{{ account.label }}</span>
                    <el-tooltip v-if="account.marketplace.upload_protocol === 'ftp'" :content="$t('settings.marketplaces.plain_ftp_hint')">
                        <el-tag type="warning" size="small" round class="flex-none">{{ $t('settings.marketplaces.plain_ftp') }}</el-tag>
                    </el-tooltip>
                </div>
                <p class="truncate text-xs leading-[18px] text-[var(--el-text-color-secondary)]">{{ subline(account) }}</p>
            </div>

            <div class="min-w-0">
                <p class="flex items-center gap-1.5 whitespace-nowrap font-medium" :class="TEXT[state(account, checking)]">
                    <span class="size-1.5 flex-none rounded-full" :class="DOT[state(account, checking)]" />
                    {{ $t(`settings.marketplaces.status.${state(account, checking)}`) }}
                </p>
                <p class="flex min-w-0 gap-2 whitespace-nowrap text-xs leading-[18px] text-[var(--el-text-color-secondary)]">
                    <span class="truncate">{{ detail(account, state(account, checking)) }}</span>
                    <template v-if="state(account, checking) === 'invalid_credentials'">
                        <el-link type="primary" underline="never" class="!text-xs" @click="emit('edit', account, true)">
                            {{ $t('settings.marketplaces.status.update_password') }}
                        </el-link>
                    </template>
                    <template v-else-if="['active', 'unreachable', 'check_failed'].includes(state(account, checking))">
                        <el-link type="primary" underline="never" class="!text-xs" @click="emit('check', account)">
                            {{ $t('settings.marketplaces.status.check_now') }}
                        </el-link>
                    </template>
                </p>
            </div>

            <div class="flex items-center gap-1.5">
                <el-tooltip :content="$t(account.status === 'disabled' ? 'settings.marketplaces.turn_on' : 'settings.marketplaces.turn_off')">
                    <el-switch
                        :model-value="account.status !== 'disabled'"
                        :disabled="checking.has(account.id)"
                        class="mr-1.5"
                        @update:model-value="emit('toggle', account, $event)"
                    />
                </el-tooltip>
                <el-button size="small" @click="emit('edit', account, false)">{{ $t('settings.marketplaces.edit') }}</el-button>
                <el-tooltip :content="$t('settings.marketplaces.remove')">
                    <el-button size="small" text circle class="connection-list__remove" @click="emit('remove', account)">
                        <Trash2 :size="15" :stroke-width="2" />
                    </el-button>
                </el-tooltip>
            </div>
        </div>
    </div>
</template>

<style scoped lang="scss">
.connection-list__remove {
    color: var(--el-text-color-secondary);

    &:hover {
        color: var(--el-color-danger);
        background: var(--el-color-danger-light-9);
    }
}
</style>
