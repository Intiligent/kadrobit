<script setup>
import { computed } from 'vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { Send } from 'lucide-vue-next';

const props = defineProps({
    // Works that go out with the next batch
    count: { type: Number, default: 0 },
    // Connected marketplace accounts
    accounts: { type: Number, default: 0 },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['open']);

const off = computed(() => props.disabled || !props.count || !props.accounts);

const tooltip = computed(() => {
    if (!props.accounts) {
        return trans('library.publishing.button.no_accounts');
    }

    if (!props.count) {
        return trans('library.publishing.button.none_ready');
    }

    return transChoice('library.publishing.button.publish', props.count, { count: props.count });
});
</script>

<template>
    <el-tooltip :content="tooltip" placement="bottom">
        <!-- A wrapper: a disabled button gets no mouse events, and the tooltip must still say why. -->
        <span class="inline-flex">
            <el-badge :value="count" :hidden="off" :max="999" :offset="[-2, 2]">
                <el-button type="danger" :disabled="off" :aria-label="tooltip" class="publish-button" @click="emit('open')">
                    <Send :size="17" :stroke-width="1.9" />
                </el-button>
            </el-badge>
        </span>
    </el-tooltip>
</template>

<style scoped lang="scss">
// Square, as tall as the search, sort and view controls next to it (the theme's component size).
.publish-button {
    width: var(--el-component-size);
    height: var(--el-component-size);
    padding: 0;
}

// The count sits on a red button: dark, with an outline, so it does not merge into it.
:deep(.el-badge__content) {
    background: var(--el-text-color-primary);
    border: 1px solid var(--el-bg-color);
}
</style>
