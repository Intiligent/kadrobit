<script setup>
import { computed, ref } from 'vue';
import { ChevronDown, ChevronRight, Folder } from 'lucide-vue-next';

const props = defineProps({
    // [{ id, name, count, children }], counts include subfolders
    folders: { type: Array, required: true },
});

const selected = defineModel('selected', { type: String, default: null });

// Folders start expanded; a click on the chevron collapses one.
const collapsed = ref(new Set());

function toggle(id) {
    const next = new Set(collapsed.value);
    next.has(id) ? next.delete(id) : next.add(id);
    collapsed.value = next;
}

// The visible rows, flattened with their depth: one list renders faster than nested components.
const rows = computed(() => {
    const out = [];
    const walk = (nodes, depth) => {
        for (const node of nodes) {
            out.push({ node, depth });

            if (node.children.length && !collapsed.value.has(node.id)) {
                walk(node.children, depth + 1);
            }
        }
    };

    walk(props.folders, 0);

    return out;
});
</script>

<template>
    <div>
        <button
            v-for="{ node, depth } in rows"
            :key="node.id"
            type="button"
            class="folder-row"
            :class="{ 'is-active': selected === node.id }"
            :style="{ paddingLeft: `${4 + depth * 18}px` }"
            @click="selected = node.id"
        >
            <span class="folder-row__toggle" @click.stop="node.children.length && toggle(node.id)">
                <template v-if="node.children.length">
                    <ChevronRight v-if="collapsed.has(node.id)" :size="14" :stroke-width="1.75" />
                    <ChevronDown v-else :size="14" :stroke-width="1.75" />
                </template>
            </span>
            <Folder :size="16" :stroke-width="1.75" class="flex-none" />
            <span class="min-w-0 flex-1 truncate">{{ node.name }}</span>
            <span class="folder-row__count">{{ node.count || '' }}</span>
        </button>
    </div>
</template>

<style scoped lang="scss">
.folder-row {
    display: flex;
    gap: 8px;
    align-items: center;
    width: 100%;
    height: 34px;
    padding-right: 10px;
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
</style>
