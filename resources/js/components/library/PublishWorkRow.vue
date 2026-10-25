<script setup>
import { computed } from 'vue';
import { getActiveLanguage, trans } from 'laravel-vue-i18n';
import { ChevronDown, CircleCheck, CircleX, FileText, Film, Image, TriangleAlert } from 'lucide-vue-next';
import MarketplaceLogo from '@/components/settings/MarketplaceLogo.vue';
import { formatBytes, formatDuration, formatNumberParams } from '@/utils/format';

const props = defineProps({
    // A row of workRows() (utils/publishing.js)
    row: { type: Object, required: true },
    // Connected accounts by id (MarketplaceAccountResource)
    connections: { type: Object, required: true },
    expanded: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle', 'expand', 'pair']);

const TAG = { ok: 'success', warning: 'warning', error: 'danger', skipped: 'info' };

const item = computed(() => props.row.item);

// Title first; the file name moves to the line below. A work without a title shows its file name only once.
const meta = computed(() => [
    item.value.title ? item.value.original_filename : null,
    item.value.width && item.value.height ? `${item.value.width}×${item.value.height}` : null,
].filter(Boolean));

// Shown instead of "3 marketplaces" when not every pair goes out.
const summary = computed(() => {
    const { sending, total } = props.row;

    if (!total) {
        return item.value.accounts.length ? '—' : trans('library.publishing.popover.already_sent');
    }

    return sending === total
        ? null
        : trans('library.publishing.popover.partial', { sending, total });
});

const locale = getActiveLanguage();

const delivery = (connection) => {
    const kind = connection.marketplace.metadata_delivery;

    return kind ? trans(`library.publishing.popover.delivery.${kind}`) : null;
};

const filesLine = (connection) => [item.value.original_filename, formatBytes(item.value.size_bytes, locale), delivery(connection)]
    .filter(Boolean)
    .join(' · ');

const checkText = (check, translate) => translate(`library.checks.${check.key}`, formatNumberParams(check.params, locale));
</script>

<template>
    <div class="border-t border-[var(--el-border-color-extra-light)]">
        <div class="flex items-center gap-3 py-2.5 pl-4 pr-3.5">
            <el-checkbox :model-value="row.chosen" :disabled="disabled || !row.selectable" @update:model-value="emit('toggle')" />

            <div class="publish-row__thumb" :class="{ 'opacity-45': !row.chosen }">
                <el-image v-if="item.thumbnail_url" :src="item.thumbnail_url" fit="cover" class="size-full" />
                <component :is="item.type === 'video' ? Film : Image" v-else :size="16" :stroke-width="1.75" class="text-[var(--el-text-color-placeholder)]" />
                <span v-if="item.type === 'video' && item.duration_ms" class="publish-row__duration">{{ formatDuration(item.duration_ms) }}</span>
            </div>

            <div class="min-w-0 flex-1" :class="{ 'opacity-45': !row.chosen }">
                <p class="truncate font-semibold">{{ item.title || item.original_filename }}</p>
                <p class="flex min-w-0 gap-[7px] whitespace-nowrap text-xs leading-[18px] text-[var(--el-text-color-secondary)]">
                    <template v-for="(part, index) in meta" :key="index">
                        <span v-if="index" class="flex-none text-[var(--el-text-color-placeholder)]">·</span>
                        <span :class="index ? 'flex-none' : 'min-w-0 truncate'">{{ part }}</span>
                    </template>
                    <span v-if="meta.length" class="flex-none text-[var(--el-text-color-placeholder)]">·</span>
                    <span class="flex-none">{{ $tChoice('library.publishing.popover.keywords', item.keywords, { count: item.keywords }) }}</span>
                </p>
            </div>

            <button
                type="button"
                class="publish-row__summary"
                :class="{ 'opacity-45': !row.chosen }"
                :disabled="!item.accounts.length"
                :aria-expanded="expanded"
                @click="emit('expand')"
            >
                <span :class="{ 'text-[var(--el-text-color-secondary)]': summary }">
                    {{ summary ?? $tChoice('library.publishing.popover.marketplaces', row.total, { count: row.total }) }}
                </span>
                <ChevronDown v-if="item.accounts.length" :size="14" :stroke-width="2" class="text-[var(--el-text-color-secondary)] transition-transform" :class="{ 'rotate-180': expanded }" />
            </button>
        </div>

        <div v-if="expanded" class="flex flex-col gap-1.5 pb-3 pl-[84px] pr-3.5">
            <p v-if="!row.pairs.length" class="text-xs text-[var(--el-text-color-secondary)]">{{ $t('library.publishing.popover.pick_marketplace') }}</p>

            <div v-for="pair in row.pairs" :key="pair.account" class="publish-row__pair">
                <div class="flex items-center gap-2">
                    <el-tooltip
                        :content="$t(pair.state === 'error' ? 'library.publishing.popover.pair_blocked' : pair.state === 'skipped' ? 'library.publishing.popover.pair_include' : 'library.publishing.popover.pair_skip')"
                        placement="top"
                    >
                        <el-checkbox
                            :model-value="pair.state !== 'error' && pair.state !== 'skipped'"
                            :disabled="disabled || pair.state === 'error'"
                            @update:model-value="emit('pair', pair.account)"
                        />
                    </el-tooltip>
                    <MarketplaceLogo :name="connections[pair.account].marketplace.name" :logo-url="connections[pair.account].marketplace.logo_url" :size="20" />
                    <span class="truncate font-semibold">{{ connections[pair.account].label || connections[pair.account].marketplace.name }}</span>
                    <span class="flex-none text-[11px] text-[var(--el-text-color-placeholder)]">{{ connections[pair.account].marketplace.upload_protocol?.toUpperCase() }}</span>
                    <span class="flex-1" />
                    <el-tag :type="TAG[pair.state]" size="small" round disable-transitions>{{ $t(`library.publishing.popover.pair.${pair.state}`) }}</el-tag>
                </div>

                <div class="flex flex-col gap-[3px] pl-6 text-xs leading-[18px]" :class="{ 'opacity-50': pair.state === 'skipped' }">
                    <span class="flex items-start gap-1.5 text-[var(--el-text-color-secondary)]">
                        <FileText :size="12" :stroke-width="2" class="mt-[3px] flex-none" />{{ filesLine(connections[pair.account]) }}
                    </span>
                    <span v-for="check in pair.errors" :key="check.key" class="flex items-start gap-1.5 text-[var(--el-color-danger)]">
                        <CircleX :size="12" :stroke-width="2.25" class="mt-[3px] flex-none" />{{ checkText(check, $t) }}
                    </span>
                    <span v-for="check in pair.warnings" :key="check.key" class="flex items-start gap-1.5 text-[var(--el-color-warning-dark-2)]">
                        <TriangleAlert :size="12" :stroke-width="2.25" class="mt-[3px] flex-none" />{{ checkText(check, $t) }}
                    </span>
                    <span v-if="!pair.errors.length && !pair.warnings.length" class="flex items-start gap-1.5 text-[var(--el-color-success-dark-2)]">
                        <CircleCheck :size="12" :stroke-width="2.25" class="mt-[3px] flex-none" />{{ $t('library.publishing.popover.checks_passed') }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped lang="scss">
.publish-row__thumb {
    position: relative;
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    overflow: hidden;
    background: var(--el-fill-color);
    border-radius: 6px;
}

.publish-row__duration {
    position: absolute;
    bottom: 3px;
    left: 3px;
    height: 14px;
    padding: 0 4px;
    font-size: 9px;
    font-weight: 600;
    line-height: 14px;
    color: var(--el-color-white);
    background: var(--el-overlay-color-light);
    border-radius: 3px;
}

.publish-row__summary {
    display: flex;
    flex: none;
    gap: 8px;
    align-items: center;
    height: 30px;
    padding: 0 6px 0 8px;
    font-weight: 500;
    white-space: nowrap;
    color: var(--el-text-color-primary);
    cursor: pointer;
    background: transparent;
    border-radius: 6px;

    &:hover:not(:disabled) {
        background: var(--el-fill-color-light);
    }

    &:disabled {
        cursor: default;
    }
}

.publish-row__pair {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 9px 12px 10px;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-light);
    border-radius: 8px;
}
</style>
