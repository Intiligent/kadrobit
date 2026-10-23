<script setup>
import { nextTick, onMounted, ref, watch } from 'vue';
import { Folder } from 'lucide-vue-next';

/**
 * Inline name field of the folder tree, for a new folder and for renaming.
 * Enter or leaving the field saves, Escape cancels; an unchanged or empty name cancels too.
 * The parent closes the field once the save succeeds.
 */
const props = defineProps({
    initial: { type: String, default: '' },
    // Message of the last refused save; the field stays open until it is fixed or cancelled.
    error: { type: String, default: null },
    saving: { type: Boolean, default: false },
    depth: { type: Number, default: 0 },
});

const emit = defineEmits(['submit', 'cancel']);

const name = ref(props.initial);
const field = ref(null);
let done = false;
let sent = null;

async function focus() {
    await nextTick();
    field.value?.focus();
    field.value?.select();
}

onMounted(focus);

// The field is disabled while saving and loses focus: a refused name gets it back for fixing.
watch(() => props.error, (error) => error && focus());

// Leaving the field after a refused name gives up instead of sending the same name again.
function submit({ leaving = false } = {}) {
    if (done || props.saving) {
        return;
    }

    const value = name.value.trim();

    if (!value || value === props.initial || (leaving && props.error && value === sent)) {
        cancel();

        return;
    }

    sent = value;
    emit('submit', value);
}

function cancel() {
    done = true;
    emit('cancel');
}
</script>

<template>
    <div class="folder-name" :style="{ paddingLeft: `${4 + depth * 18}px` }">
        <div class="flex h-[34px] items-center gap-2 pr-1">
            <span class="w-5 flex-none" />
            <Folder :size="16" :stroke-width="1.75" class="flex-none text-[var(--el-text-color-secondary)]" />
            <el-input
                ref="field"
                v-model="name"
                size="small"
                maxlength="100"
                :disabled="saving"
                :placeholder="$t('library.folders.name_placeholder')"
                :class="{ 'is-error': error }"
                @keydown.enter.prevent="submit"
                @keydown.esc.prevent="cancel"
                @blur="submit({ leaving: true })"
            />
        </div>
        <p v-if="error" class="pb-1 pl-[46px] pr-1 text-xs leading-4 text-[var(--el-color-danger)]">{{ error }}</p>
    </div>
</template>

<style scoped lang="scss">
.folder-name :deep(.is-error .el-input__wrapper) {
    box-shadow: 0 0 0 1px var(--el-color-danger) inset;
}
</style>
