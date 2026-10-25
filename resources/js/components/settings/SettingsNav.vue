<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { SETTINGS_MARKETPLACES_URL, SETTINGS_METADATA_URL } from '@/config/constant';

const page = usePage();

// Sections without a page yet stay visible but disabled, so the nav keeps its final shape.
const groups = [
    { key: 'account', items: [{ key: 'profile' }, { key: 'security' }, { key: 'notifications' }, { key: 'data' }] },
    { key: 'workspace', items: [{ key: 'marketplaces', href: SETTINGS_MARKETPLACES_URL }, { key: 'metadata', href: SETTINGS_METADATA_URL }, { key: 'publishing' }] },
    { key: 'billing', items: [{ key: 'billing' }] },
];

const isActive = (item) => item.href && page.url.startsWith(item.href);
</script>

<template>
    <aside class="flex w-60 flex-none flex-col border-r border-[var(--el-border-color-light)] bg-[var(--el-bg-color)]">
        <div class="flex h-14 flex-none items-center px-[18px] text-[15px] font-semibold">{{ $t('settings.title') }}</div>

        <el-scrollbar class="flex-1">
            <nav class="flex flex-col gap-3 px-2 pb-3">
                <div v-for="group in groups" :key="group.key" class="flex flex-col gap-px">
                    <p class="settings-nav__label">{{ $t(`settings.nav.groups.${group.key}`) }}</p>

                    <template v-for="item in group.items" :key="item.key">
                        <Link v-if="item.href" :href="item.href" class="settings-nav__item" :class="{ 'is-active': isActive(item) }">
                            {{ $t(`settings.nav.${item.key}`) }}
                        </Link>
                        <el-tooltip v-else :content="$t('settings.nav.soon')" placement="right">
                            <span class="settings-nav__item is-disabled">{{ $t(`settings.nav.${item.key}`) }}</span>
                        </el-tooltip>
                    </template>
                </div>
            </nav>
        </el-scrollbar>
    </aside>
</template>

<style scoped lang="scss">
.settings-nav__label {
    padding: 8px 10px 6px;
    font-size: 11px;
    font-weight: 600;
    color: var(--el-text-color-placeholder);
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

.settings-nav__item {
    display: flex;
    align-items: center;
    height: 32px;
    padding: 0 10px;
    font-weight: 500;
    color: var(--el-text-color-primary);
    border-radius: 6px;

    &:hover {
        background: var(--el-fill-color);
    }

    &.is-active {
        color: var(--el-color-primary);
        background: var(--el-color-primary-light-9);
    }

    &.is-disabled {
        color: var(--el-text-color-placeholder);
        cursor: not-allowed;

        &:hover {
            background: transparent;
        }
    }
}
</style>
