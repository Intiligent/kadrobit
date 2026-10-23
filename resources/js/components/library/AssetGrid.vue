<script setup>
import { computed } from 'vue';
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
</script>

<template>
    <div class="relative min-h-0 flex-1">
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
    </div>
</template>
