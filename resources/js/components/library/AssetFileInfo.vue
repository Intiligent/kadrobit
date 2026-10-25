<script setup>
import { computed } from 'vue';
import { getActiveLanguage, trans } from 'laravel-vue-i18n';
import { cameraRows } from '@/utils/cameraInfo';
import { formatBytes, formatDateTime, formatDuration, megapixels } from '@/utils/format';

const props = defineProps({
    asset: { type: Object, required: true },
});

// An image preview; a video shows its thumbnail when it has no playback copy.
const image = computed(() => (props.asset.type === 'video' ? props.asset.thumbnail_url : props.asset.preview_url));

const rows = computed(() => {
    const a = props.asset;
    const locale = getActiveLanguage();
    const location = [a.location?.city, a.location?.country].filter(Boolean).join(', ');

    return [
        ['type', trans(`library.types.${a.type}`)],
        ['original', a.original_filename],
        ['format', a.mime],
        ['dimensions', a.width && a.height ? `${a.width} × ${a.height} px` : null],
        // Counted from the work's own size, not from EXIF: rounded down, like the marketplace checks.
        ['megapixels', a.type === 'video' || !megapixels(a.width, a.height) ? null : `${megapixels(a.width, a.height)} MP`],
        ['duration', a.type === 'video' ? formatDuration(a.duration_ms) : null],
        ['size', formatBytes(a.size_bytes, locale)],
        ['captured', formatDateTime(a.captured_at, locale)],
        ['location', location],
        ['uploaded', formatDateTime(a.created_at, locale)],
        ['checksum', a.checksum],
    ].filter(([, value]) => value);
});

const camera = computed(() => cameraRows(props.asset.camera));
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="aspect-[4/3] overflow-hidden rounded-lg bg-[var(--el-fill-color-dark)]">
            <!-- A video's preview is its playback copy, without sound; the thumbnail shows until it starts. -->
            <video
                v-if="asset.type === 'video' && asset.preview_url"
                :key="asset.preview_url"
                :src="asset.preview_url"
                :poster="asset.thumbnail_url || undefined"
                controls
                muted
                loop
                playsinline
                preload="metadata"
                class="size-full object-contain"
            />
            <!-- A video without its playback copy (uploaded before KB-19) shows the thumbnail. -->
            <el-image v-else-if="image" :src="image" fit="contain" class="size-full" :preview-src-list="[image]" />
        </div>

        <el-descriptions :column="1" size="small" class="file-info">
            <el-descriptions-item v-for="[key, value] in rows" :key="key" :label="$t(`library.file.${key}`)">
                <span :class="{ 'font-mono text-xs break-all': key === 'checksum' }">{{ value }}</span>
            </el-descriptions-item>
        </el-descriptions>

        <template v-if="camera.length">
            <h3 class="text-sm font-bold">{{ $t('library.camera.title') }}</h3>
            <el-descriptions :column="1" size="small" class="file-info">
                <el-descriptions-item v-for="[key, value] in camera" :key="key" :label="$t(`library.camera.${key}`)">
                    {{ value }}
                </el-descriptions-item>
            </el-descriptions>
        </template>
    </div>
</template>

<style scoped lang="scss">
// Element Plus sets the small size's 12px as a fixed SCSS value (descriptions.scss, $descriptions-cell-font-size),
// not a CSS variable, under four classes: matched here with as many, plus the scope attribute, to win. 13px is the
// theme's small font size.
.file-info.el-descriptions--small :deep(.el-descriptions__table .el-descriptions__cell) {
    font-size: var(--el-font-size-small);
}

.file-info :deep(.el-descriptions__label) {
    display: inline-block;
    min-width: 110px;
    color: var(--el-text-color-secondary);
}
</style>
