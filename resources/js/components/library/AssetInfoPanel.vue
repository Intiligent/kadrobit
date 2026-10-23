<script setup>
import { ref } from 'vue';
import { Image, X } from 'lucide-vue-next';

defineProps({
    // The selected work; its details are filled in by KB-17.
    asset: { type: Object, required: true },
});

const emit = defineEmits(['close']);

const tab = ref('metadata');
const TABS = ['metadata', 'marketplaces', 'file'];
</script>

<template>
    <aside class="info-panel">
        <header class="flex flex-none items-center gap-3 px-5 pb-3 pt-4">
            <div class="info-panel__thumb">
                <Image :size="18" :stroke-width="1.75" />
            </div>
            <div class="min-w-0 flex-1">
                <!-- TODO(KB-17): file name, status and file summary. -->
            </div>
            <button type="button" class="info-panel__close" :aria-label="$t('library.panel.close')" @click="emit('close')">
                <X :size="18" :stroke-width="1.75" />
            </button>
        </header>

        <el-tabs v-model="tab" class="info-panel__tabs">
            <el-tab-pane v-for="key in TABS" :key="key" :name="key" :label="$t(`library.panel.tabs.${key}`)">
                <!-- TODO(KB-17): metadata form, marketplace checks, file info. -->
            </el-tab-pane>
        </el-tabs>

        <footer class="flex flex-none items-center gap-2.5 border-t border-[var(--el-border-color-light)] px-5 py-3">
            <!-- TODO(KB-17): autosave state and status actions. -->
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
