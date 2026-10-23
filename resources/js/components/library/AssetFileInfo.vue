<script setup>
import { computed } from 'vue';
import { getActiveLanguage, trans } from 'laravel-vue-i18n';
import { formatBytes, formatDateTime, formatDuration } from '@/utils/format';

const props = defineProps({
    asset: { type: Object, required: true },
});

const rows = computed(() => {
    const a = props.asset;
    const locale = getActiveLanguage();
    const location = [a.location?.city, a.location?.country].filter(Boolean).join(', ');

    return [
        ['type', trans(`library.types.${a.type}`)],
        ['original', a.original_filename],
        ['format', a.mime],
        ['dimensions', a.width && a.height ? `${a.width} × ${a.height} px` : null],
        ['duration', a.type === 'video' ? formatDuration(a.duration_ms) : null],
        ['size', formatBytes(a.size_bytes, locale)],
        ['captured', formatDateTime(a.captured_at, locale)],
        ['location', location],
        ['uploaded', formatDateTime(a.created_at, locale)],
        ['checksum', a.checksum],
    ].filter(([, value]) => value);
});
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="aspect-[4/3] overflow-hidden rounded-lg bg-[var(--el-fill-color-dark)]">
            <el-image v-if="asset.preview_url" :src="asset.preview_url" fit="contain" class="size-full" :preview-src-list="[asset.preview_url]" />
        </div>

        <el-descriptions :column="1" size="small" class="file-info">
            <el-descriptions-item v-for="[key, value] in rows" :key="key" :label="$t(`library.file.${key}`)">
                <span :class="{ 'font-mono text-xs break-all': key === 'checksum' }">{{ value }}</span>
            </el-descriptions-item>
        </el-descriptions>
    </div>
</template>

<style scoped lang="scss">
.file-info :deep(.el-descriptions__label) {
    display: inline-block;
    min-width: 110px;
    color: var(--el-text-color-secondary);
}
</style>
