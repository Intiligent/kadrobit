<script setup>
defineProps({
    label: { type: String, required: true },
    // Filled by the last generation and not edited since (utils/generation.js)
    generated: { type: Boolean, default: false },
});
</script>

<template>
    <span class="flex w-full items-center justify-between gap-2">
        <span class="flex items-center gap-2">
            {{ label }}
            <el-tooltip v-if="generated" :content="$t('library.generation.ai_hint')" placement="top">
                <span class="field-label__ai">AI</span>
            </el-tooltip>
        </span>
        <!-- Field actions and counters -->
        <span class="flex items-center gap-2 font-normal">
            <slot />
        </span>
    </span>
</template>

<style scoped lang="scss">
.field-label__ai {
    padding: 0 6px;
    font-size: 11px;
    font-weight: 600;
    line-height: 18px;
    color: var(--el-color-primary);
    cursor: default;
    background: var(--el-color-primary-light-9);
    border-radius: 9px;
}
</style>
