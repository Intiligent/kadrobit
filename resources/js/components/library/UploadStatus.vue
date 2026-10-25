<script setup>
import { ref, watch } from 'vue';
import { CircleCheck, CircleX, Film, Image, X } from 'lucide-vue-next';
import { percentOf } from '@/composables/useAssetUpload';
import { isVideoFile } from '@/utils/localPreview';

const props = defineProps({
    // From useAssetUpload
    items: { type: Array, required: true },
    summary: { type: Object, required: true },
    // Files that never reached the server because the page was left mid-upload
    interrupted: { type: Number, default: 0 },
});

const emit = defineEmits(['retry', 'remove', 'clear']);

// Opens by itself when new files are added; closes on an outside click like any popover.
const open = ref(false);

// What the file is waiting for while its bar fills.
function stage(item) {
    if (item.status === 'uploading') {
        return 'uploading';
    }

    return item.processing ? 'processing' : 'in_queue';
}

watch(() => props.summary.total, (total, previous) => {
    if (total > (previous ?? 0)) {
        open.value = true;
    }
}, { immediate: true });
</script>

<template>
    <el-popover v-if="items.length || interrupted" v-model:visible="open" placement="bottom-end" :width="360" trigger="click" popper-class="upload-status">
        <template #reference>
            <button type="button" class="upload-status__trigger" :aria-label="$t('library.upload.status.open')">
                <el-progress
                    v-if="summary.active"
                    type="circle"
                    :percentage="summary.percent"
                    :width="22"
                    :stroke-width="3"
                    :show-text="false"
                />
                <CircleX v-else-if="summary.failed" :size="20" :stroke-width="1.75" class="text-[var(--el-color-danger)]" />
                <CircleCheck v-else :size="20" :stroke-width="1.75" class="text-[var(--el-color-success)]" />
            </button>
        </template>

        <div class="flex items-center justify-between gap-3 px-4 pb-2 pt-3">
            <p class="font-semibold">
                {{ summary.active
                    ? $tChoice('library.upload.status.title_active', summary.active, { count: summary.active })
                    : $t('library.upload.status.title_done') }}
            </p>
            <span class="text-[13px] text-[var(--el-text-color-secondary)]">
                {{ $t('library.upload.status.progress', { done: summary.done, total: summary.total }) }}
            </span>
        </div>
        <div class="px-4 pb-3">
            <el-progress :percentage="summary.percent" :show-text="false" :stroke-width="4" :status="summary.active ? undefined : (summary.failed ? 'warning' : 'success')" />
        </div>

        <p v-if="interrupted" class="border-t border-[var(--el-border-color-lighter)] px-4 py-2 text-xs leading-4 text-[var(--el-color-warning-dark-2)]">
            {{ $tChoice('library.upload.status.interrupted', interrupted, { count: interrupted }) }}
        </p>

        <el-scrollbar max-height="320px" class="border-t border-[var(--el-border-color-lighter)]">
            <div v-for="item in items" :key="item.key" class="upload-row">
                <div class="upload-row__thumb">
                    <img v-if="item.previewUrl || item.thumbnailUrl" :src="item.previewUrl || item.thumbnailUrl" alt="" class="size-full object-cover">
                    <component :is="isVideoFile({ type: item.mime, name: item.name }) ? Film : Image" v-else :size="16" :stroke-width="1.75" />
                </div>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-[13px] leading-[18px]">{{ item.name }}</p>

                    <template v-if="item.status === 'uploading' || item.status === 'processing'">
                        <el-progress :percentage="percentOf(item)" :show-text="false" :stroke-width="3" class="my-1" />
                        <p class="text-xs leading-4 text-[var(--el-text-color-secondary)]">
                            {{ $t(`library.upload.status.${stage(item)}`) }} · {{ percentOf(item) }}%
                        </p>
                    </template>
                    <p v-else-if="item.status === 'error'" class="text-xs leading-4 text-[var(--el-color-danger)]">
                        {{ item.error }}
                        <el-link v-if="item.retryable" type="primary" underline="never" class="!ml-1 !align-baseline !text-xs" @click="emit('retry', item)">
                            {{ $t('library.upload.status.retry') }}
                        </el-link>
                    </p>
                    <p v-else class="text-xs leading-4 text-[var(--el-text-color-secondary)]">
                        {{ $t(`library.upload.status.${item.status}`) }}
                    </p>
                </div>

                <CircleCheck v-if="item.status === 'done'" :size="18" :stroke-width="2" class="flex-none text-[var(--el-color-success)]" />
                <button
                    v-else-if="item.status === 'error' || item.status === 'queued'"
                    type="button"
                    class="upload-row__remove"
                    :aria-label="$t('library.upload.status.remove')"
                    @click="emit('remove', item)"
                >
                    <X :size="14" :stroke-width="2" />
                </button>
            </div>
        </el-scrollbar>

        <div v-if="summary.done || interrupted" class="flex justify-end border-t border-[var(--el-border-color-lighter)] px-4 py-2">
            <el-link underline="never" class="!text-[13px]" @click="emit('clear')">{{ $t('library.upload.status.clear') }}</el-link>
        </div>
    </el-popover>
</template>

<style scoped lang="scss">
.upload-status__trigger {
    display: flex;
    align-items: center;
    justify-content: center;
    // As tall as the other toolbar controls (the theme's component size).
    width: var(--el-component-size);
    height: var(--el-component-size);
    cursor: pointer;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color);
    border-radius: var(--el-border-radius-base);

    &:hover {
        border-color: var(--el-border-color-hover);
    }
}

.upload-row {
    display: flex;
    gap: 10px;
    align-items: center;
    padding: 8px 16px;

    & + & {
        border-top: 1px solid var(--el-border-color-extra-light);
    }
}

.upload-row__thumb {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    overflow: hidden;
    color: var(--el-text-color-placeholder);
    background: var(--el-fill-color);
    border-radius: 6px;
}

.upload-row__remove {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    color: var(--el-text-color-placeholder);
    cursor: pointer;
    background: transparent;
    border: 0;
    border-radius: 4px;

    &:hover {
        color: var(--el-text-color-primary);
        background: var(--el-fill-color);
    }
}
</style>

<style lang="scss">
// The popper is teleported to <body>: unscoped, keyed by popper-class.
.el-popover.upload-status {
    padding: 0;
}
</style>
