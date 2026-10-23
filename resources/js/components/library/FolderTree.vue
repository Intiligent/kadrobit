<script setup>
import { computed, ref } from 'vue';
import { ChevronDown, ChevronRight, Ellipsis, Folder, FolderPlus, Pencil, Trash2 } from 'lucide-vue-next';
import FolderNameInput from '@/components/library/FolderNameInput.vue';
import { DRAG_ASSETS, DRAG_FOLDER } from '@/config/constant';
import { dragHasType as hasType } from '@/utils/drag';
import { dropPlacement } from '@/utils/folders';

const props = defineProps({
    // [{ id, name, count, children }], counts include subfolders
    folders: { type: Array, required: true },
    // The same folders as a flat list in tree order: [{ id, parent_id, name }]
    flat: { type: Array, default: () => [] },
    // No menu and no dragging until the email is confirmed.
    readonly: { type: Boolean, default: false },
    // Inline name field: { mode: 'create', parentId, key } or { mode: 'rename', id, key }; null when closed.
    // `key` is new for every opening, so a new field never reuses the state of the previous one.
    editing: { type: Object, default: null },
    // { error, saving } of the name field
    editState: { type: Object, default: () => ({ error: null, saving: false }) },
});

const selected = defineModel('selected', { type: String, default: null });

const emit = defineEmits(['edit', 'submit', 'cancel', 'delete', 'move-folder', 'move-assets']);

// Folders start expanded; a click on the chevron collapses one.
const collapsed = ref(new Set());

function toggle(id) {
    const next = new Set(collapsed.value);
    next.has(id) ? next.delete(id) : next.add(id);
    collapsed.value = next;
}

function expand(id) {
    if (collapsed.value.has(id)) {
        toggle(id);
    }
}

const creatingIn = computed(() => (props.editing?.mode === 'create' ? props.editing.parentId ?? null : undefined));
const renaming = computed(() => (props.editing?.mode === 'rename' ? props.editing.id : null));

// The visible rows, flattened with their depth: one list renders faster than nested components.
// A new folder's name field goes after the parent's subfolders.
const rows = computed(() => {
    const out = [];
    const walk = (nodes, depth) => {
        for (const node of nodes) {
            out.push({ key: node.id, node, depth });

            const open = !collapsed.value.has(node.id) || creatingIn.value === node.id;

            if (open) {
                walk(node.children, depth + 1);
            }

            if (creatingIn.value === node.id) {
                out.push({ key: `new-${props.editing.key}`, node: null, depth: depth + 1 });
            }
        }
    };

    walk(props.folders, 0);

    if (creatingIn.value === null) {
        out.push({ key: `new-${props.editing.key}`, node: null, depth: 0 });
    }

    return out;
});

// The row whose menu is open keeps showing its menu button.
const menuFor = ref(null);

function onCommand(command, node) {
    if (command === 'subfolder') {
        expand(node.id);
        emit('edit', { mode: 'create', parentId: node.id });
    } else if (command === 'rename') {
        emit('edit', { mode: 'rename', id: node.id });
    } else if (command === 'delete') {
        emit('delete', node);
    }
}

// Dragging. A folder dropped on the top or bottom quarter of a row goes before or after it,
// anywhere else inside it; works always go inside.

const draggingId = ref(null);
// { id, zone: 'before' | 'inside' | 'after' }
const dropTarget = ref(null);

function onDragStart(node, event) {
    draggingId.value = node.id;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData(DRAG_FOLDER, node.id);
}

function onDragEnd() {
    draggingId.value = null;
    dropTarget.value = null;
}

function zoneOf(event) {
    const box = event.currentTarget.getBoundingClientRect();
    const y = (event.clientY - box.top) / box.height;

    return y < 0.25 ? 'before' : y > 0.75 ? 'after' : 'inside';
}

function onDragOver(node, event) {
    let zone = null;

    if (hasType(event, DRAG_ASSETS)) {
        zone = 'inside';
    } else if (hasType(event, DRAG_FOLDER) && draggingId.value) {
        zone = zoneOf(event);

        if (!dropPlacement(props.flat, draggingId.value, node.id, zone)) {
            zone = null;
        }
    }

    if (!zone) {
        dropTarget.value = null;

        return;
    }

    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    dropTarget.value = { id: node.id, zone };
}

function onDragLeave(node, event) {
    if (dropTarget.value?.id === node.id && !event.currentTarget.contains(event.relatedTarget)) {
        dropTarget.value = null;
    }
}

function onDrop(node, event) {
    const target = dropTarget.value;
    dropTarget.value = null;

    if (!target) {
        return;
    }

    if (hasType(event, DRAG_ASSETS)) {
        emit('move-assets', node.id, JSON.parse(event.dataTransfer.getData(DRAG_ASSETS) || '[]'));
    } else if (draggingId.value) {
        const placement = dropPlacement(props.flat, draggingId.value, node.id, target.zone);

        if (placement) {
            if (target.zone === 'inside') {
                expand(node.id);
            }

            emit('move-folder', draggingId.value, placement);
        }
    }

    draggingId.value = null;
}

const dropClass = (node) => (dropTarget.value?.id === node.id ? `is-drop-${dropTarget.value.zone}` : null);
</script>

<template>
    <div role="tree">
        <template v-for="row in rows" :key="row.key">
            <FolderNameInput
                v-if="!row.node || renaming === row.node.id"
                :key="`name-${editing?.key}`"
                :initial="row.node?.name ?? ''"
                :depth="row.depth"
                :error="editState.error"
                :saving="editState.saving"
                @submit="emit('submit', $event)"
                @cancel="emit('cancel')"
            />

            <div
                v-else
                role="treeitem"
                tabindex="0"
                class="folder-row"
                :class="[{ 'is-active': selected === row.node.id, 'is-dragging': draggingId === row.node.id, 'is-menu-open': menuFor === row.node.id }, dropClass(row.node)]"
                :style="{ paddingLeft: `${4 + row.depth * 18}px` }"
                :draggable="!readonly"
                @click="selected = row.node.id"
                @keydown.enter.prevent="selected = row.node.id"
                @dragstart="onDragStart(row.node, $event)"
                @dragend="onDragEnd"
                @dragover="onDragOver(row.node, $event)"
                @dragleave="onDragLeave(row.node, $event)"
                @drop="onDrop(row.node, $event)"
            >
                <span class="folder-row__toggle" @click.stop="row.node.children.length && toggle(row.node.id)">
                    <template v-if="row.node.children.length">
                        <ChevronRight v-if="collapsed.has(row.node.id)" :size="14" :stroke-width="1.75" />
                        <ChevronDown v-else :size="14" :stroke-width="1.75" />
                    </template>
                </span>
                <Folder :size="16" :stroke-width="1.75" class="flex-none" />
                <span class="min-w-0 flex-1 truncate">{{ row.node.name }}</span>
                <span class="folder-row__count">{{ row.node.count || '' }}</span>

                <el-dropdown
                    v-if="!readonly"
                    trigger="click"
                    placement="bottom-end"
                    class="folder-row__menu"
                    @command="onCommand($event, row.node)"
                    @visible-change="menuFor = $event ? row.node.id : null"
                >
                    <button type="button" class="folder-row__menu-button" :aria-label="$t('library.folders.actions')" @click.stop>
                        <Ellipsis :size="16" :stroke-width="1.75" />
                    </button>
                    <template #dropdown>
                        <el-dropdown-menu>
                            <el-dropdown-item command="subfolder">
                                <FolderPlus :size="16" :stroke-width="1.75" class="mr-2" />{{ $t('library.folders.new_subfolder') }}
                            </el-dropdown-item>
                            <el-dropdown-item command="rename">
                                <Pencil :size="16" :stroke-width="1.75" class="mr-2" />{{ $t('library.folders.rename') }}
                            </el-dropdown-item>
                            <el-dropdown-item command="delete" divided class="folder-row__delete">
                                <Trash2 :size="16" :stroke-width="1.75" class="mr-2" />{{ $t('library.folders.delete') }}
                            </el-dropdown-item>
                        </el-dropdown-menu>
                    </template>
                </el-dropdown>
            </div>
        </template>
    </div>
</template>

<style scoped lang="scss">
.folder-row {
    position: relative;
    display: flex;
    gap: 8px;
    align-items: center;
    width: 100%;
    height: 34px;
    padding-right: 6px;
    font-size: 14px;
    color: var(--el-text-color-primary);
    text-align: left;
    cursor: pointer;
    background: transparent;
    border-radius: 8px;
    outline: none;

    &:hover,
    &:focus-visible {
        background: var(--el-fill-color-light);
    }

    &.is-active {
        font-weight: 600;
        color: var(--el-color-primary);
        background: var(--el-color-primary-light-9);
    }

    &.is-dragging {
        opacity: 0.5;
    }

    &.is-drop-inside {
        background: var(--el-color-primary-light-9);
        box-shadow: 0 0 0 1px var(--el-color-primary) inset;
    }

    // A line between rows shows where a folder goes among its siblings.
    &.is-drop-before::before,
    &.is-drop-after::after {
        position: absolute;
        right: 6px;
        left: 6px;
        height: 2px;
        content: '';
        background: var(--el-color-primary);
        border-radius: 1px;
    }

    &.is-drop-before::before {
        top: -1px;
    }

    &.is-drop-after::after {
        bottom: -1px;
    }
}

.folder-row__toggle {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    color: var(--el-text-color-placeholder);
}

.folder-row__count {
    font-size: 13px;
    font-weight: 400;
    color: var(--el-text-color-placeholder);
}

// The menu button replaces the count on hover, and stays while its menu is open.
.folder-row__menu {
    display: none;
}

.folder-row:hover,
.folder-row:focus-within,
.folder-row.is-menu-open {
    .folder-row__menu {
        display: flex;
    }

    .folder-row__count {
        display: none;
    }
}

.folder-row__menu-button {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
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

.folder-row__delete {
    color: var(--el-color-danger);
}
</style>
