<script setup>
import { Inbox, Layers, Plus } from 'lucide-vue-next';
import FolderTree from '@/components/library/FolderTree.vue';
import MarketplaceAccounts from '@/components/library/MarketplaceAccounts.vue';

defineProps({
    // Folder tree: [{ id, name, count, children }]
    folders: { type: Array, default: () => [] },
    // Live (not archived) works: { all, unsorted }
    counts: { type: Object, required: true },
    accounts: { type: Array, default: () => [] },
});

// 'all', 'unsorted' or a folder id
const place = defineModel('place', { type: String, default: 'all' });

const views = [
    { key: 'all', icon: Layers },
    { key: 'unsorted', icon: Inbox },
];
</script>

<template>
    <aside class="flex w-[264px] flex-none flex-col border-r border-[var(--el-border-color-light)] bg-[var(--el-bg-color)]">
        <div class="flex h-16 flex-none items-center justify-between pl-[18px] pr-3">
            <h2 class="text-base font-semibold">{{ $t('library.explorer.library') }}</h2>
            <el-tooltip :content="$t('library.explorer.new_folder_soon')" placement="bottom">
                <span class="explorer__icon-button is-disabled"><Plus :size="18" :stroke-width="1.75" /></span>
            </el-tooltip>
        </div>

        <el-scrollbar class="min-h-0 flex-1" view-class="px-2 pb-3">
            <button
                v-for="view in views"
                :key="view.key"
                type="button"
                class="explorer__item"
                :class="{ 'is-active': place === view.key }"
                @click="place = view.key"
            >
                <component :is="view.icon" :size="16" :stroke-width="1.75" class="flex-none" />
                <span class="min-w-0 flex-1 truncate">{{ $t(`library.explorer.views.${view.key}`) }}</span>
                <span class="explorer__count">{{ counts[view.key] || '' }}</span>
            </button>

            <template v-if="folders.length">
                <p class="explorer__label">{{ $t('library.explorer.folders') }}</p>
                <FolderTree v-model:selected="place" :folders="folders" />
            </template>
        </el-scrollbar>

        <MarketplaceAccounts :accounts="accounts" />
    </aside>
</template>

<style scoped lang="scss">
.explorer__icon-button {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    color: var(--el-text-color-secondary);
    border-radius: 6px;

    &.is-disabled {
        cursor: not-allowed;
    }
}

.explorer__label {
    padding: 22px 10px 8px;
    font-size: 12px;
    font-weight: 600;
    line-height: 16px;
    color: var(--el-text-color-placeholder);
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

.explorer__item {
    display: flex;
    gap: 10px;
    align-items: center;
    width: 100%;
    height: 34px;
    padding: 0 10px;
    font-size: 14px;
    color: var(--el-text-color-primary);
    text-align: left;
    cursor: pointer;
    background: transparent;
    border: 0;
    border-radius: 8px;

    &:hover {
        background: var(--el-fill-color-light);
    }

    &.is-active {
        font-weight: 600;
        color: var(--el-color-primary);
        background: var(--el-color-primary-light-9);
    }
}

.explorer__count {
    font-size: 13px;
    font-weight: 400;
    color: var(--el-text-color-placeholder);
}
</style>
