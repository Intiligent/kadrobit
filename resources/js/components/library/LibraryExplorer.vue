<script setup>
import { computed, ref } from 'vue';
import { Inbox, Layers, Plus } from 'lucide-vue-next';
import FolderTree from '@/components/library/FolderTree.vue';
import MarketplaceAccounts from '@/components/library/MarketplaceAccounts.vue';
import { DRAG_ASSETS, DRAG_FOLDER } from '@/config/constant';
import { dragHasType as hasType } from '@/utils/drag';

const props = defineProps({
    // Folder tree: [{ id, name, count, children }]
    folders: { type: Array, default: () => [] },
    // The same folders as a flat list in tree order: [{ id, parent_id, name }]
    flat: { type: Array, default: () => [] },
    // Live (not archived) works: { all, unsorted }
    counts: { type: Object, required: true },
    accounts: { type: Array, default: () => [] },
    // Folders can be changed only with a confirmed email.
    readonly: { type: Boolean, default: false },
    // Inline name field of the tree, see FolderTree
    editing: { type: Object, default: null },
    editState: { type: Object, default: () => ({ error: null, saving: false }) },
});

const emit = defineEmits(['edit', 'submit', 'cancel', 'delete', 'move-folder', 'move-assets']);

// 'all', 'unsorted' or a folder id
const place = defineModel('place', { type: String, default: 'all' });

const views = [
    { key: 'all', icon: Layers },
    { key: 'unsorted', icon: Inbox },
];

const creatingAtTop = computed(() => props.editing?.mode === 'create' && !props.editing.parentId);

// Drop targets outside the tree: works onto Unsorted, folders onto the "Folders" label (top level, last).
const dropOn = ref(null);

function accepts(target, event) {
    return target === 'unsorted' ? hasType(event, DRAG_ASSETS) : hasType(event, DRAG_FOLDER);
}

function onDragOver(target, event) {
    if (!props.readonly && accepts(target, event)) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        dropOn.value = target;
    }
}

function onDragLeave(target, event) {
    if (dropOn.value === target && !event.currentTarget.contains(event.relatedTarget)) {
        dropOn.value = null;
    }
}

function onDrop(target, event) {
    dropOn.value = null;

    if (!accepts(target, event)) {
        return;
    }

    if (target === 'unsorted') {
        emit('move-assets', null, JSON.parse(event.dataTransfer.getData(DRAG_ASSETS) || '[]'));

        return;
    }

    const id = event.dataTransfer.getData(DRAG_FOLDER);
    const roots = props.flat.filter((f) => !f.parent_id && f.id !== id);

    if (id) {
        emit('move-folder', id, { parent_id: null, position: roots.length });
    }
}
</script>

<template>
    <aside class="flex w-[264px] flex-none flex-col border-r border-[var(--el-border-color-light)] bg-[var(--el-bg-color)]">
        <div class="flex h-16 flex-none items-center justify-between pl-[18px] pr-3">
            <h2 class="text-base font-semibold">{{ $t('library.explorer.library') }}</h2>
            <!-- The span gets the hover: a disabled button fires no mouse events, so its tooltip would never open. -->
            <el-tooltip :content="$t(readonly ? 'library.folders.verify_to_edit' : 'library.explorer.new_folder')" placement="bottom">
                <span class="flex" :class="{ 'cursor-not-allowed': readonly }">
                    <button
                        type="button"
                        class="explorer__icon-button"
                        :class="{ 'is-disabled': readonly }"
                        :disabled="readonly"
                        :aria-label="$t('library.explorer.new_folder')"
                        @click="emit('edit', { mode: 'create', parentId: null })"
                    >
                        <Plus :size="18" :stroke-width="1.75" />
                    </button>
                </span>
            </el-tooltip>
        </div>

        <el-scrollbar class="min-h-0 flex-1" view-class="px-2 pb-3">
            <button
                v-for="view in views"
                :key="view.key"
                type="button"
                class="explorer__item"
                :class="{ 'is-active': place === view.key, 'is-drop': dropOn === view.key }"
                @click="place = view.key"
                @dragover="view.key === 'unsorted' && onDragOver('unsorted', $event)"
                @dragleave="onDragLeave(view.key, $event)"
                @drop="view.key === 'unsorted' && onDrop('unsorted', $event)"
            >
                <component :is="view.icon" :size="16" :stroke-width="1.75" class="flex-none" />
                <span class="min-w-0 flex-1 truncate">{{ $t(`library.explorer.views.${view.key}`) }}</span>
                <span class="explorer__count">{{ counts[view.key] || '' }}</span>
            </button>

            <template v-if="folders.length || creatingAtTop">
                <p
                    class="explorer__label"
                    :class="{ 'is-drop': dropOn === 'top' }"
                    @dragover="onDragOver('top', $event)"
                    @dragleave="onDragLeave('top', $event)"
                    @drop="onDrop('top', $event)"
                >
                    {{ $t('library.explorer.folders') }}
                    <span v-if="dropOn === 'top'" class="explorer__drop-hint">{{ $t('library.folders.drop_root') }}</span>
                </p>
                <FolderTree
                    v-model:selected="place"
                    :folders="folders"
                    :flat="flat"
                    :readonly="readonly"
                    :editing="editing"
                    :edit-state="editState"
                    @edit="emit('edit', $event)"
                    @submit="emit('submit', $event)"
                    @cancel="emit('cancel')"
                    @delete="emit('delete', $event)"
                    @move-folder="(id, placement) => emit('move-folder', id, placement)"
                    @move-assets="(folderId, ids) => emit('move-assets', folderId, ids)"
                />
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
    cursor: pointer;
    background: transparent;
    border: 0;
    border-radius: 6px;

    &:hover:not(.is-disabled) {
        color: var(--el-text-color-primary);
        background: var(--el-fill-color);
    }

    // The wrapping span shows the cursor and the tooltip.
    &.is-disabled {
        pointer-events: none;
        opacity: 0.5;
    }
}

.explorer__drop-hint {
    font-weight: 400;
    text-transform: none;
    letter-spacing: 0;
}

.explorer__label {
    display: flex;
    gap: 8px;
    align-items: baseline;
    padding: 22px 10px 8px;
    font-size: 12px;
    font-weight: 600;
    line-height: 16px;
    color: var(--el-text-color-placeholder);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    border-radius: 6px;

    &.is-drop {
        color: var(--el-color-primary);
        background: var(--el-color-primary-light-9);
    }
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

    &.is-drop {
        background: var(--el-color-primary-light-9);
        box-shadow: 0 0 0 1px var(--el-color-primary) inset;
    }
}

.explorer__count {
    font-size: 13px;
    font-weight: 400;
    color: var(--el-text-color-placeholder);
}
</style>
