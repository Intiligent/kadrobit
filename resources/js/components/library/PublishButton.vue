<script setup>
import { computed } from 'vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { LoaderCircle, Send } from 'lucide-vue-next';

const props = defineProps({
    // Works that go out with the next batch
    count: { type: Number, default: 0 },
    // Connected marketplace accounts
    accounts: { type: Number, default: 0 },
    disabled: { type: Boolean, default: false },
    // Some work is on its way to a marketplace (publishingBusy()): a spinner instead of the icon, and the popover
    // opens even with nothing new to send, to show how it goes.
    delivering: { type: Boolean, default: false },
    // The publish popover is open: its own content says the rest, the tooltip would cover it.
    active: { type: Boolean, default: false },
});

const off = computed(() => props.disabled || (!props.count && !props.delivering) || !props.accounts);

const tooltip = computed(() => {
    if (!props.accounts) {
        return trans('library.publishing.button.no_accounts');
    }

    if (!props.count && props.delivering) {
        return trans('library.publishing.button.delivering');
    }

    if (!props.count) {
        return trans('library.publishing.button.none_ready');
    }

    return transChoice('library.publishing.button.publish', props.count, { count: props.count });
});
</script>

<template>
    <el-tooltip :content="tooltip" placement="bottom" :disabled="active">
        <!-- A wrapper: a disabled button gets no mouse events, and the tooltip must still say why.
             The click that opens the publish popover comes from its reference (PublishPopover.vue). -->
        <span class="inline-flex">
            <el-badge :value="count" :hidden="off || !count" :max="999" :offset="[-2, 2]">
                <el-button type="danger" :disabled="off" :aria-label="tooltip" class="publish-button" :class="{ 'is-active': active }">
                    <LoaderCircle v-if="delivering" :size="17" :stroke-width="1.9" class="animate-spin" />
                    <Send v-else :size="17" :stroke-width="1.9" />
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

    // Pressed while its popover is open.
    &.is-active {
        background: var(--el-color-danger-dark-2);
        border-color: var(--el-color-danger-dark-2);
    }
}

// The count sits on a red button: dark, with an outline, so it does not merge into it.
:deep(.el-badge__content) {
    background: var(--el-text-color-primary);
    border: 1px solid var(--el-bg-color);
}
</style>
