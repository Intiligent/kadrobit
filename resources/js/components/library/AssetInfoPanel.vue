<script setup>
import { computed } from 'vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { Film, Image, PenTool, Sparkles, TriangleAlert, X } from 'lucide-vue-next';
import AssetFileInfo from '@/components/library/AssetFileInfo.vue';
import AssetMetadataForm from '@/components/library/AssetMetadataForm.vue';
import MarketplaceChecks from '@/components/library/MarketplaceChecks.vue';
import { countIssues } from '@/utils/marketplaceRules';
import { formatDuration } from '@/utils/format';
import { useStoredRef } from '@/utils/storage';

const props = defineProps({
    asset: { type: Object, required: true },
    accounts: { type: Array, default: () => [] },
    // Category lists by marketplace code
    categories: { type: Object, default: () => ({}) },
    // Folder tree for "Move to folder": [{ id, name, children }]
    folders: { type: Array, default: () => [] },
    // Autosave state of this work: 'pending' | 'saving' | 'saved' | 'error'
    saveState: { type: String, default: null },
    // { field: message } from the last failed save
    errors: { type: Object, default: () => ({}) },
    // Read-only until the email is confirmed: the API refuses edits before that.
    readonly: { type: Boolean, default: false },
    // The delete request is running.
    deleting: { type: Boolean, default: false },
});

const emit = defineEmits(['update', 'status', 'delete', 'close']);

// Remembered per browser, like the tile size: the panel reopens on the same tab.
const tab = useStoredRef('library.panel-tab', 'metadata', { allowed: ['metadata', 'marketplaces', 'file'] });

const TYPE_ICON = { photo: Image, video: Film, vector: PenTool, illustration: Sparkles };
const STATUS_TAG = { draft: 'info', ready: 'success', archived: 'info' };

const subtitle = computed(() => {
    const a = props.asset;
    const parts = [trans(`library.types.${a.type}`)];

    if (a.width && a.height) {
        parts.push(`${a.width}×${a.height}`);
    }

    if (a.type === 'video' && a.duration_ms) {
        parts.push(formatDuration(a.duration_ms));
    }

    return parts.join(' · ');
});

const issues = computed(() => countIssues(props.asset, props.accounts));
const canMarkReady = computed(() => Boolean(props.asset.title?.trim() && props.asset.keywords.length));

const footNote = computed(() => {
    if (props.readonly) {
        return { text: trans('library.fields.verify_to_edit'), warn: true };
    }

    if (props.errors.status) {
        return { text: props.errors.status, warn: true };
    }

    if (props.saveState === 'pending' || props.saveState === 'saving') {
        return { text: trans('library.footer.saving') };
    }

    if (props.saveState === 'error') {
        return { text: trans('library.footer.save_failed'), warn: true };
    }

    if (props.asset.status === 'draft' && !canMarkReady.value) {
        return { text: trans('library.footer.needs_metadata'), warn: true };
    }

    if (props.asset.status === 'ready' && issues.value) {
        return { text: transChoice('library.footer.issues', issues.value, { count: issues.value }), warn: true };
    }

    return { text: props.saveState === 'saved' ? trans('library.footer.saved') : '' };
});
</script>

<template>
    <aside class="info-panel">
        <header class="flex flex-none items-center gap-3 px-5 pb-3 pt-4">
            <div class="info-panel__thumb">
                <el-image v-if="asset.thumbnail_url" :src="asset.thumbnail_url" fit="cover" class="size-full" />
                <component :is="TYPE_ICON[asset.type]" v-else :size="18" :stroke-width="1.75" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="truncate font-semibold leading-5" :title="asset.original_filename">{{ asset.original_filename }}</div>
                <div class="mt-0.5 flex items-center gap-1.5 text-[13px] leading-5 text-[var(--el-text-color-secondary)]">
                    <el-tag size="small" round disable-transitions :type="STATUS_TAG[asset.status]">
                        {{ $t(`library.status.${asset.status}`) }}
                    </el-tag>
                    <span class="truncate">{{ subtitle }}</span>
                </div>
            </div>
            <button type="button" class="info-panel__close" :aria-label="$t('library.panel.close')" @click="emit('close')">
                <X :size="18" :stroke-width="1.75" />
            </button>
        </header>

        <el-tabs v-model="tab" class="info-panel__tabs">
            <el-tab-pane name="metadata" :label="$t('library.panel.tabs.metadata')">
                <AssetMetadataForm
                    :asset="asset"
                    :accounts="accounts"
                    :categories="categories"
                    :folders="folders"
                    :errors="errors"
                    :disabled="readonly"
                    @update="emit('update', $event)"
                />
            </el-tab-pane>
            <el-tab-pane name="marketplaces">
                <template #label>
                    <el-badge :value="issues" :hidden="!issues" type="warning" :offset="[8, 2]">
                        {{ $t('library.panel.tabs.marketplaces') }}
                    </el-badge>
                </template>
                <MarketplaceChecks :asset="asset" :accounts="accounts" />
            </el-tab-pane>
            <el-tab-pane name="file" :label="$t('library.panel.tabs.file')">
                <AssetFileInfo :asset="asset" />
            </el-tab-pane>
        </el-tabs>

        <footer class="info-panel__footer flex flex-none items-center gap-2.5 border-t border-[var(--el-border-color-light)] px-5 py-3">
            <!-- One line at most, so the footer keeps its height: warnings are an icon with the text in a tooltip. -->
            <div class="flex min-w-0 flex-1 items-center">
                <el-tooltip v-if="footNote.warn" :content="footNote.text" placement="top">
                    <TriangleAlert
                        :size="18"
                        :stroke-width="1.75"
                        :aria-label="footNote.text"
                        class="flex-none cursor-help text-[var(--el-color-warning-dark-2)]"
                    />
                </el-tooltip>
                <span v-else class="truncate text-[13px] leading-[18px] text-[var(--el-text-color-secondary)]">{{ footNote.text }}</span>
            </div>

            <template v-if="asset.status === 'draft'">
                <!-- ⌘-click (Ctrl-click off macOS) archives without asking: it is undone with Restore. -->
                <el-button :disabled="readonly" @click="emit('status', 'archived', { confirm: !($event.metaKey || $event.ctrlKey) })">
                    {{ $t('library.footer.archive') }}
                </el-button>
                <el-button type="primary" :disabled="readonly || !canMarkReady" @click="emit('status', 'ready')">
                    {{ $t('library.footer.mark_ready') }}
                </el-button>
            </template>

            <template v-else-if="asset.status === 'ready'">
                <el-button :disabled="readonly" @click="emit('status', 'draft')">{{ $t('library.footer.back_to_draft') }}</el-button>
                <!-- TODO: publish flow (asset_listings). The button keeps its place in the layout until then. -->
                <el-tooltip :content="$t('library.footer.publish_soon')">
                    <span><el-button type="primary" disabled>{{ $t('library.footer.publish') }}</el-button></span>
                </el-tooltip>
            </template>

            <template v-else>
                <el-button type="danger" plain :disabled="readonly" :loading="deleting" @click="emit('delete')">{{ $t('library.footer.delete') }}</el-button>
                <el-button type="primary" :disabled="readonly || deleting" @click="emit('status', 'draft')">{{ $t('library.footer.restore') }}</el-button>
            </template>
        </footer>
    </aside>
</template>

<style scoped lang="scss">
.info-panel {
    display: flex;
    flex: none;
    flex-direction: column;
    width: 416px;
    min-height: 0;
    background: var(--el-bg-color);
    border-left: 1px solid var(--el-border-color-light);
}

.info-panel__thumb {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 64px;
    height: 48px;
    overflow: hidden;
    color: var(--el-text-color-placeholder);
    background: var(--el-fill-color-dark);
    border-radius: 6px;
}

.info-panel__close {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    color: var(--el-text-color-secondary);
    cursor: pointer;
    background: transparent;
    border: 0;
    border-radius: 6px;

    &:hover {
        color: var(--el-text-color-primary);
        background: var(--el-fill-color);
    }
}

// The footer's gap spaces its buttons; Element Plus' own margin between adjacent buttons would add to it.
.info-panel__footer .el-button + .el-button {
    margin-left: 0;
}

// Tabs fill the panel: fixed header, scrolling content.
.info-panel__tabs {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-height: 0;

    :deep(.el-tabs__header) {
        flex: none;
        margin: 0;
        padding: 0 20px;
    }

    :deep(.el-tabs__item) {
        height: 44px;
        font-size: 14px;
    }

    :deep(.el-tabs__content) {
        flex: 1;
        min-height: 0;
        padding: 20px;
        overflow: auto;
    }
}
</style>
