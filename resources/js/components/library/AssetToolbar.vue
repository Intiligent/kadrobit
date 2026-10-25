<script setup>
import { Grid3x3, LayoutGrid, Search } from 'lucide-vue-next';

defineProps({
    title: { type: String, required: true },
    // Parent folder names before the title
    path: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    // Works per status tab: { all, draft, ready, archived }
    counts: { type: Object, required: true },
    // Work types present in this place, for the type filter
    types: { type: Array, default: () => [] },
});

const query = defineModel('query', { type: String, default: '' });
const sort = defineModel('sort', { type: String, default: 'newest' });
const size = defineModel('size', { type: String, default: 'large' });
const status = defineModel('status', { type: String, default: 'all' });
const type = defineModel('type', { type: String, default: 'all' });

const SORTS = ['newest', 'oldest', 'keywords', 'title'];
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
            </el-input>

            <el-select v-model="sort" class="toolbar__sort">
                <el-option v-for="key in SORTS" :key="key" :value="key" :label="$t(`library.toolbar.sort.${key}`)" />
            </el-select>

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

            <div class="ml-auto flex items-center gap-1">
                <button
                    v-for="key in ['all', ...types]"
                    :key="key"
                    type="button"
                    class="toolbar__type"
                    :class="{ 'is-active': type === key }"
                    @click="type = key"
                >
                    {{ $t(`library.toolbar.types.${key}`) }}
                </button>
            </div>
        </div>
    </header>
</template>

<style scoped lang="scss">

.toolbar__search {
    flex: 0 1 264px;
    min-width: 160px;
}

.toolbar__sort {
    width: 152px;
}

// Icon-only segments: the chosen one grey rather than filled with the primary colour.
.toolbar__sizes {
    flex: none;

    :deep(.el-radio-button__inner) {
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

.toolbar__type {
    height: 28px;
    padding: 0 12px;
    font-size: 13px;
    color: var(--el-text-color-secondary);
    cursor: pointer;
    background: transparent;
    border: 0;
    border-radius: 14px;

    &:hover {
        color: var(--el-text-color-primary);
    }

    &.is-active {
        font-weight: 500;
        color: var(--el-bg-color);
        background: var(--el-text-color-primary);
    }
}
</style>
