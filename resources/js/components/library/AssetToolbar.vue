<script setup>
import { getActiveLanguage } from 'laravel-vue-i18n';
import { Grid3x3, LayoutGrid, Search } from 'lucide-vue-next';
import LibraryFilters from '@/components/library/LibraryFilters.vue';
import { emptyFilters } from '@/utils/libraryFilters';
import { formatTotalSize } from '@/utils/format';

defineProps({
    title: { type: String, required: true },
    // Parent folder names before the title
    path: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    // Works per status tab: { all, draft, ready, archived }
    counts: { type: Object, required: true },
    // Work types present in this place, for the type filter
    types: { type: Array, default: () => [] },
    // Connected marketplace accounts, for the marketplace filter
    accounts: { type: Array, default: () => [] },
    // The user's tags, for the tag filter: [{ id, name }]
    tags: { type: Array, default: () => [] },
    // Works per filter choice (utils/libraryFilters.js filterCounts())
    filterCounts: { type: Object, default: () => ({ types: {}, marketplaces: {}, tags: {} }) },
    // Works shown with the current filters
    shown: { type: Number, default: 0 },
    // Size of their files together, in bytes
    shownBytes: { type: Number, default: 0 },
});

const locale = getActiveLanguage();

const query = defineModel('query', { type: String, default: '' });
const sort = defineModel('sort', { type: String, default: 'newest' });
const size = defineModel('size', { type: String, default: 'large' });
const status = defineModel('status', { type: String, default: 'all' });
// { types, marketplaces, tags } (utils/libraryFilters.js)
const filters = defineModel('filters', { type: Object, default: emptyFilters });

const STATUSES = ['all', 'draft', 'ready', 'submitted', 'archived'];
const SIZES = [
    { key: 'large', icon: LayoutGrid },
    { key: 'small', icon: Grid3x3 },
];
</script>

<template>
    <header class="toolbar flex-none px-7">
        <div class="flex h-16 items-center gap-3.5">
            <div class="flex min-w-0 flex-1 items-baseline gap-2">
                <span v-if="path.length" class="truncate text-[var(--el-text-color-secondary)]">{{ path.join(' / ') }} /</span>
                <h1 class="truncate text-xl font-semibold leading-7">{{ title }}</h1>
                <span class="whitespace-nowrap text-[var(--el-text-color-placeholder)]">
                    {{ $tChoice('library.toolbar.works', total, { count: total }) }}
                </span>
            </div>

            <el-input
                v-model="query"
                :placeholder="$t('library.toolbar.search')"
                clearable
                class="toolbar__search"
            >
                <template #prefix><Search :size="16" :stroke-width="1.75" /></template>
                <!-- Filters and sorting open from the search field's end: one input group. -->
                <template #append>
                    <LibraryFilters
                        v-model:sort="sort"
                        v-model:filters="filters"
                        :types="types"
                        :accounts="accounts"
                        :tags="tags"
                        :counts="filterCounts"
                        :shown="shown"
                    />
                </template>
            </el-input>

            <el-radio-group v-model="size" class="toolbar__sizes">
                <el-radio-button v-for="item in SIZES" :key="item.key" :value="item.key" :aria-label="$t(`library.toolbar.size_${item.key}`)">
                    <component :is="item.icon" :size="18" :stroke-width="1.75" />
                </el-radio-button>
            </el-radio-group>

            <slot name="actions" />
        </div>

        <div class="flex items-center border-b border-[var(--el-border-color-light)]">
            <el-tabs v-model="status" class="toolbar__tabs">
                <el-tab-pane v-for="key in STATUSES" :key="key" :name="key">
                    <template #label>
                        {{ $t(`library.status.${key}`) }}
                        <span class="ml-1.5 text-[13px] font-normal text-[var(--el-text-color-placeholder)]">{{ counts[key] }}</span>
                    </template>
                </el-tab-pane>
            </el-tabs>
            <span class="ml-auto flex-none pl-4 text-[13px] text-[var(--el-text-color-secondary)]">
                {{ $t('library.toolbar.total_size', { size: formatTotalSize(shownBytes, locale) }) }}
            </span>
        </div>
    </header>
</template>

<style scoped lang="scss">

.toolbar__search {
    flex: 0 1 380px;
    min-width: 260px;

    // The filters button fills the append slot, on the field's own background. The slot's border is an inset
    // shadow: the 1px padding keeps the button's hover background from covering it.
    :deep(.el-input-group__append) {
        padding: 1px 1px 1px 0;
        overflow: hidden;
        background: var(--el-bg-color);
    }
}

// Icon-only segments: the chosen one grey rather than filled with the primary colour.
.toolbar__sizes {
    flex: none;

    // As tall as the search and sort next to it (the theme's component size), not the radio button's own 32px.
    :deep(.el-radio-button__inner) {
        display: flex;
        align-items: center;
        height: var(--el-component-size);
        padding-right: 8px;
        padding-left: 8px;
        color: var(--el-text-color-secondary);

        // The 18px icon stands in for a 14px line of text: it must not make the button taller.
        svg {
            display: block;
            margin: -2px 0;
        }
    }

    :deep(.el-radio-button__original-radio:checked + .el-radio-button__inner) {
        color: var(--el-text-color-primary);
        background: var(--el-fill-color);
        border-color: var(--el-border-color);
        box-shadow: -1px 0 0 0 var(--el-border-color);
    }
}

// The tabs are a filter here: no content area, and the bottom border belongs to the whole row.
.toolbar__tabs {
    :deep(.el-tabs__header) {
        margin: 0;
    }

    :deep(.el-tabs__nav-wrap::after) {
        display: none;
    }

    :deep(.el-tabs__content) {
        display: none;
    }

    :deep(.el-tabs__item) {
        height: 44px;
        padding: 0 13px;
        font-size: 14px;
        font-weight: 400;
        color: var(--el-text-color-secondary);

        &.is-active {
            color: var(--el-text-color-primary);
        }
    }

    :deep(.el-tabs__item:nth-child(2)) {
        padding-left: 0;
    }

    :deep(.el-tabs__active-bar) {
        height: 3px;
    }
}
</style>
