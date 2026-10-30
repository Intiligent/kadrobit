<script setup>
import { computed, ref } from 'vue';
import { ChevronDown, Check, ListFilter } from 'lucide-vue-next';
import MarketplaceLogo from '@/components/settings/MarketplaceLogo.vue';
import { NOT_UPLOADED, activeCount, emptyFilters } from '@/utils/libraryFilters';

const props = defineProps({
    // Types offered: those in the current place, and the chosen ones so they can be cleared
    types: { type: Array, default: () => [] },
    // Connected marketplace accounts (MarketplaceAccountResource)
    accounts: { type: Array, default: () => [] },
    // Works per choice, utils/libraryFilters.js filterCounts()
    counts: { type: Object, default: () => ({ types: {}, marketplaces: {}, tags: {} }) },
    // Works shown with the current filters: the footer button
    shown: { type: Number, default: 0 },
    // The user's tags, alphabetically: [{ id, name }]
    tags: { type: Array, default: () => [] },
});

const sort = defineModel('sort', { type: String, default: 'newest' });
// { types, marketplaces, tags } (utils/libraryFilters.js)
const filters = defineModel('filters', { type: Object, default: emptyFilters });

const SORTS = ['newest', 'oldest', 'keywords', 'title'];

const open = ref(false);
const active = computed(() => activeCount(filters.value));
const dirty = computed(() => active.value > 0 || sort.value !== 'newest');

const set = (key, value) => {
    filters.value = { ...filters.value, [key]: value };
};

const marketplaces = computed(() => [
    ...props.accounts.map((account) => ({
        key: account.id,
        name: account.label || account.marketplace.name,
        account,
    })),
    { key: NOT_UPLOADED, name: null, account: null },
].map((row) => {
    const count = props.counts.marketplaces[row.key] ?? 0;

    // Nothing to find there: off, unless already chosen and still to be cleared.
    return { ...row, count, disabled: !count && !filters.value.marketplaces.includes(row.key) };
}));

// Same as the marketplaces: a tag no work here carries is off, unless already chosen.
const tagOptions = computed(() => props.tags.map((tag) => {
    const count = props.counts.tags?.[tag.id] ?? 0;

    return { ...tag, count, disabled: !count && !filters.value.tags.includes(tag.id) };
}));

function resetAll() {
    filters.value = emptyFilters();
    sort.value = 'newest';
}
</script>

<template>
    <el-popover
        v-model:visible="open"
        trigger="click"
        placement="bottom-end"
        :width="432"
        :offset="10"
        :show-arrow="true"
        popper-class="library-filters"
    >
        <template #reference>
            <el-button class="filters-button" :class="{ 'is-open': open }" :aria-expanded="open" :aria-label="$t('library.filters.button')">
                <ListFilter :size="16" :stroke-width="2" />
                <span>{{ $t('library.filters.button') }}</span>
                <span v-if="active" class="filters-button__count">{{ active }}</span>
                <ChevronDown :size="14" :stroke-width="2.25" class="filters-button__chevron" />
            </el-button>
        </template>

        <div class="filters" @keydown.esc="open = false">
            <div class="filters__body">
                <section class="filters__section">
                    <h3 class="filters__title">{{ $t('library.filters.sort') }}</h3>
                    <el-radio-group v-model="sort" class="filters__sorts">
                        <el-radio v-for="key in SORTS" :key="key" :value="key" class="filters__sort">
                            <span class="flex-1 truncate">{{ $t(`library.toolbar.sort.${key}`) }}</span>
                            <Check v-if="sort === key" :size="14" :stroke-width="2.5" class="filters__check" />
                        </el-radio>
                    </el-radio-group>
                </section>

                <section class="filters__section">
                    <h3 class="filters__title">{{ $t('library.filters.types') }}</h3>
                    <!-- Several types at once; "All" is on while none is chosen and clears the choice. -->
                    <div class="filters__types">
                        <el-checkbox-button :model-value="!filters.types.length" class="filters__type" @change="set('types', [])">
                            {{ $t('library.filters.all') }}
                        </el-checkbox-button>
                        <el-checkbox-group :model-value="filters.types" class="contents" @update:model-value="set('types', $event)">
                            <el-checkbox-button v-for="key in types" :key="key" :value="key" class="filters__type">
                                <Check v-if="filters.types.includes(key)" :size="12" :stroke-width="3" class="filters__check" />
                                {{ $t(`library.toolbar.types.${key}`) }}
                                <span class="filters__count">{{ counts.types[key] ?? 0 }}</span>
                            </el-checkbox-button>
                        </el-checkbox-group>
                    </div>
                </section>

                <section class="filters__section">
                    <div class="filters__head">
                        <h3 class="filters__title">{{ $t('library.filters.marketplaces') }}</h3>
                        <el-link v-if="filters.marketplaces.length" type="primary" underline="never" @click="set('marketplaces', [])">
                            {{ $t('library.filters.reset') }}
                        </el-link>
                    </div>
                    <el-checkbox-group :model-value="filters.marketplaces" class="filters__list" @update:model-value="set('marketplaces', $event)">
                        <el-checkbox v-for="row in marketplaces" :key="row.key" :value="row.key" :disabled="row.disabled" class="filters__checkbox">
                            <MarketplaceLogo v-if="row.account" :name="row.account.marketplace.name" :logo-url="row.account.marketplace.logo_url" :size="22" />
                            <span class="flex-1 truncate font-medium">{{ row.name ?? $t('library.filters.not_uploaded') }}</span>
                            <span v-if="row.count" class="filters__count">{{ row.count }}</span>
                        </el-checkbox>
                    </el-checkbox-group>
                </section>

                <section class="filters__section">
                    <div class="filters__head">
                        <h3 class="filters__title">{{ $t('library.filters.tags') }}</h3>
                        <el-link v-if="filters.tags.length" type="primary" underline="never" @click="set('tags', [])">
                            {{ $t('library.filters.reset') }}
                        </el-link>
                    </div>
                    <!-- Not teleported: a dropdown outside the popover would count as a click outside and close it. -->
                    <el-select
                        :model-value="filters.tags"
                        multiple
                        filterable
                        :teleported="false"
                        :placeholder="$t('library.filters.tags_placeholder')"
                        :no-data-text="$t('library.filters.tags_empty')"
                        :no-match-text="$t('library.filters.tags_no_match')"
                        @update:model-value="set('tags', $event)"
                    >
                        <el-option v-for="tag in tagOptions" :key="tag.id" :value="tag.id" :label="tag.name" :disabled="tag.disabled">
                            <span class="flex items-center justify-between gap-3">
                                <span class="truncate">{{ tag.name }}</span>
                                <span v-if="tag.count" class="filters__count">{{ tag.count }}</span>
                            </span>
                        </el-option>
                    </el-select>
                </section>
            </div>

            <footer class="filters__footer">
                <el-link :type="dirty ? 'primary' : 'info'" :disabled="!dirty" underline="never" @click="resetAll">
                    {{ $t('library.filters.reset_all') }}
                </el-link>
                <el-button type="primary" :disabled="!shown" class="ml-auto" @click="open = false">
                    {{ $tChoice('library.filters.show', shown, { count: shown }) }}
                </el-button>
            </footer>
        </div>
    </el-popover>
</template>

<style scoped lang="scss">
// Sits in the search field's append slot: the two read as one input group. Element's append rules (negative
// margins, transparent background on hover) are overridden, hence the `button.el-button` weight.
button.el-button.filters-button {
    display: flex;
    align-items: center;
    height: 100%;
    padding: 0 10px 0 11px;
    margin: 0;
    font-size: 14px;
    font-weight: 500;
    line-height: inherit;
    color: var(--el-text-color-primary);
    background: transparent;
    border: 0;
    // Inside the field's 1px border: rounded like its corners, so the hover background stays off the border.
    border-radius: 0 calc(var(--el-input-border-radius, var(--el-border-radius-base)) - 1px) calc(var(--el-input-border-radius, var(--el-border-radius-base)) - 1px) 0;

    :deep(> span) {
        gap: 6px;
    }

    &:hover,
    &:focus-visible,
    &.is-open {
        color: var(--el-text-color-primary);
        background: var(--el-fill-color-light);
    }
}

.filters-button__count {
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    font-size: 11px;
    font-weight: 600;
    line-height: 18px;
    color: var(--el-color-white);
    text-align: center;
    background: var(--el-color-primary);
    border-radius: 9px;
}

.filters-button__chevron {
    color: var(--el-text-color-secondary);
    transition: transform 0.2s ease;

    .is-open & {
        transform: rotate(180deg);
    }
}

.filters {
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 120px);
}

.filters__body {
    flex: 1;
    min-height: 0;
    overflow: auto;
}

.filters__section {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 14px 16px;

    & + & {
        border-top: 1px solid var(--el-border-color-lighter);
    }
}

.filters__head {
    display: flex;
    gap: 8px;
    align-items: center;
    height: 20px;

    .el-link {
        margin-left: auto;
    }
}

.filters__title {
    margin: 0;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.06em;
    color: var(--el-text-color-placeholder);
    text-transform: uppercase;
}

.filters__sorts {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    column-gap: 8px;
    margin: 0 -6px;
}

.filters__list {
    display: flex;
    flex-direction: column;
    margin: 0 -6px;
}

// A sort: the radio's own circle is hidden (kept for the keyboard), the chosen one is bold with a check mark.
.filters__sort.el-radio {
    height: 32px;
    padding: 0 6px;
    margin-right: 0;
    font-weight: 400;
    color: var(--el-text-color-regular);
    border-radius: 6px;

    &:hover {
        background: var(--el-fill-color-light);
    }

    :deep(.el-radio__input) {
        position: absolute;
        opacity: 0;
    }

    :deep(.el-radio__label) {
        display: flex;
        flex: 1;
        gap: 10px;
        align-items: center;
        min-width: 0;
        padding-left: 0;
        font-size: 14px;
        color: inherit;
    }

    &:hover,
    &.is-checked {
        color: var(--el-text-color-primary);
    }

    &.is-checked {
        font-weight: 600;
    }
}

// A marketplace: the whole row is the checkbox's label, with the logo, the name and the count.
.filters__checkbox {
    height: 32px;
    padding: 0 6px;
    margin-right: 0;
    border-radius: 6px;

    &:hover:not(.is-disabled) {
        background: var(--el-fill-color-light);
    }

    :deep(.el-checkbox__label) {
        display: flex;
        flex: 1;
        gap: 10px;
        align-items: center;
        min-width: 0;
        padding-left: 10px;
        color: var(--el-text-color-regular);
    }

    &.is-disabled {
        opacity: 0.45;
    }
}

.filters__check {
    flex: none;
    color: var(--el-color-primary);
}

// Segments as wide as their labels; the row still fills the width.
.filters__types {
    display: flex;
    gap: 2px;
    padding: 2px;
    background: var(--el-fill-color);
    border-radius: 8px;

    // The group zeroes its font for inline-block gaps; it has no box here, so the segments take the popover's text.
    :deep(.el-checkbox-group) {
        font-size: inherit;
        line-height: inherit;
    }
}

.filters__type {
    display: flex;
    flex: 1 1 auto;
    border-radius: 6px;

    // The segment is drawn on the label; Element's button look of the inner part is switched off.
    :deep(.el-checkbox-button__inner) {
        display: flex;
        flex: 1;
        gap: 5px;
        align-items: center;
        justify-content: center;
        height: 28px;
        padding: 0 10px;
        font-size: 13px;
        font-weight: 500;
        line-height: inherit;
        color: var(--el-text-color-secondary);
        background: transparent;
        border: 0;
        border-radius: 6px;
        outline: 0;
        box-shadow: none;
        transition: none;

        &:hover {
            color: var(--el-text-color-primary);
        }
    }

    &.is-checked {
        background: var(--el-bg-color);
        box-shadow: 0 1px 2px rgb(0 0 0 / 10%);

        :deep(.el-checkbox-button__inner) {
            color: var(--el-text-color-primary);
            background: transparent;
            box-shadow: none;
        }
    }
}

.filters__count {
    font-size: 12px;
    font-weight: 400;
    color: var(--el-text-color-placeholder);
}

.filters__footer {
    display: flex;
    flex: none;
    gap: 12px;
    align-items: center;
    padding: 12px 16px;
    background: var(--el-bg-color-page);
    border-top: 1px solid var(--el-border-color-lighter);
    border-radius: 0 0 12px 12px;
}
</style>

<style lang="scss">
// The popover is teleported out of the component: unscoped, under its own class.
.el-popover.el-popper.library-filters {
    padding: 0;
    border-radius: 12px;
    box-shadow: var(--el-box-shadow);
}
</style>
