<script setup>
import { computed, ref } from 'vue';
import PublishButton from '@/components/library/PublishButton.vue';
import PublishPanel from '@/components/library/PublishPanel.vue';

const props = defineProps({
    // Works that go out with the next batch
    count: { type: Number, default: 0 },
    // Connected marketplace accounts (MarketplaceAccountResource)
    accounts: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['published']);

const open = ref(false);
// A send on its way keeps the popover open: closing it would hide the outcome.
const busy = ref(false);

const off = computed(() => props.disabled || !props.count || !props.accounts.length);

const visible = computed({
    get: () => open.value,
    set: (value) => {
        if (!value && busy.value) {
            return;
        }

        open.value = value;
    },
});

function onPublished(data) {
    busy.value = false;
    open.value = false;
    emit('published', data);
}
</script>

<template>
    <!-- The panel exists only while the popover is open (v-if, persistent=false): it fetches the batch on every
         opening and nothing of it stays in the page between openings. -->
    <el-popover
        v-model:visible="visible"
        trigger="click"
        placement="bottom-end"
        :width="640"
        :disabled="off"
        :persistent="false"
        :show-arrow="true"
        :offset="10"
        popper-class="publish-popover"
    >
        <template #reference>
            <span class="inline-flex">
                <PublishButton :count="count" :accounts="accounts.length" :disabled="disabled" :active="open" />
            </span>
        </template>

        <PublishPanel v-if="open" :accounts="accounts" @busy="busy = $event" @published="onPublished" @close="visible = false" />
    </el-popover>
</template>

<style lang="scss">
// The popover is teleported out of the component: unscoped, under its own class.
.el-popover.el-popper.publish-popover {
    padding: 0;
    border-radius: 12px;
    box-shadow: var(--el-box-shadow);
}
</style>
