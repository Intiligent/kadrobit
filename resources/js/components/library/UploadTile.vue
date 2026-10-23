<script setup>
import { Upload } from 'lucide-vue-next';

defineProps({
    accept: { type: String, default: '' },
});

const emit = defineEmits(['files']);

// Element Plus handles picking and dropping; the files go to the library's own upload queue.
const handOver = ({ file }) => {
    emit('files', [file]);

    return Promise.resolve();
};
</script>

<template>
    <el-upload class="upload-tile" drag multiple :accept="accept" :show-file-list="false" :http-request="handOver">
        <Upload :size="20" :stroke-width="1.75" class="mb-2" />
        <span class="text-sm font-semibold">{{ $t('library.upload.tile') }}</span>
        <span class="text-[13px]">{{ $t('library.upload.tile_hint') }}</span>
    </el-upload>
</template>

<style scoped lang="scss">
.upload-tile {
    :deep(.el-upload),
    :deep(.el-upload-dragger) {
        width: 100%;
    }

    :deep(.el-upload-dragger) {
        display: flex;
        flex-direction: column;
        gap: 2px;
        align-items: center;
        justify-content: center;
        aspect-ratio: 4 / 3;
        padding: 8px;
        color: var(--el-text-color-secondary);
        background: var(--el-bg-color);
        border: 1.5px dashed var(--el-border-color-dark);
        border-radius: 8px;

        &:hover,
        &.is-dragover {
            color: var(--el-color-primary);
            background: var(--el-color-primary-light-9);
            border-color: var(--el-color-primary);
        }
    }
}
</style>
