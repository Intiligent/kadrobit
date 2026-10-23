<script setup>
import { computed, ref } from 'vue';
import { Upload } from 'lucide-vue-next';
import AssetCard from '@/components/library/AssetCard.vue';
import UploadTile from '@/components/library/UploadTile.vue';

const props = defineProps({
    assets: { type: Array, required: true },
    selectedId: { type: String, default: null },
    size: { type: String, default: 'large' },
    captions: { type: Boolean, default: true },
    accept: { type: String, default: '' },
    emptyTitle: { type: String, default: '' },
    emptyText: { type: String, default: '' },
});

const emit = defineEmits(['select', 'files']);

const gridStyle = computed(() => ({
    gridTemplateColumns: `repeat(auto-fill, minmax(${props.size === 'small' ? 180 : 260}px, 1fr))`,
    gap: props.size === 'small' ? '16px' : '24px',
}));

// Drag-and-drop anywhere over the grid. dragenter/dragleave fire for every child,
// so a depth counter tells when the pointer really left the area.
const dragDepth = ref(0);
const dragging = computed(() => dragDepth.value > 0);
const hasFiles = (event) => Array.from(event.dataTransfer?.types ?? []).includes('Files');

function onDragEnter(event) {
    if (hasFiles(event)) {
        dragDepth.value += 1;
    }
}

function onDragLeave(event) {
    if (hasFiles(event)) {
        dragDepth.value = Math.max(0, dragDepth.value - 1);
    }
}

function onDrop(event) {
    dragDepth.value = 0;

    // A drop on the upload tile is already handled by el-upload.
    if (event.target.closest?.('.el-upload-dragger')) {
        return;
    }

    const files = Array.from(event.dataTransfer?.files ?? []);

    if (files.length) {
        emit('files', files);
    }
}
</script>

<template>
    <div class="relative min-h-0 flex-1" @dragenter.prevent="onDragEnter" @dragover.prevent @dragleave="onDragLeave" @drop.prevent="onDrop">
        <el-scrollbar>
            <div class="grid px-7 pb-8 pt-6" :style="gridStyle">
                <!-- Always the first tile, whatever the filters. -->
                <UploadTile :accept="accept" @files="emit('files', $event)" />

                <AssetCard
                    v-for="asset in assets"
                    :key="asset.id"
                    :asset="asset"
                    :selected="asset.id === selectedId"
                    :captions="captions"
                    @select="emit('select', $event)"
                />
            </div>
        </el-scrollbar>

        <!-- Centred in the whole grid area, not just under the toolbar. -->
        <div v-if="!assets.length" class="pointer-events-none absolute inset-0 flex items-center justify-center">
            <el-empty :image-size="160">
                <template #description>
                    <p class="font-semibold text-[var(--el-text-color-primary)]">{{ emptyTitle }}</p>
                    <p class="text-[var(--el-text-color-secondary)]">{{ emptyText }}</p>
                </template>
            </el-empty>
        </div>

        <div v-if="dragging" class="grid-drop">
            <Upload :size="32" :stroke-width="1.75" />
            <p class="text-base font-semibold">{{ $t('library.upload.drop_title') }}</p>
            <p class="text-[var(--el-text-color-secondary)]">{{ $t('library.upload.drop_hint') }}</p>
        </div>
    </div>
</template>

<style scoped lang="scss">
.grid-drop {
    position: absolute;
    inset: 12px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    align-items: center;
    justify-content: center;
    color: var(--el-color-primary);
    pointer-events: none;
    background: var(--el-color-primary-light-9);
    border: 2px dashed var(--el-color-primary);
    border-radius: 12px;
    opacity: 0.95;
}
</style>
