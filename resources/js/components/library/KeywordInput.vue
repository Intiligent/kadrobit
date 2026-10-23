<script setup>
import { ref } from 'vue';
import { KEYWORDS_HIGHLIGHTED, KEYWORDS_MAX } from '@/config/constant';

const keywords = defineModel({ type: Array, default: () => [] });

const props = defineProps({
    max: { type: Number, default: KEYWORDS_MAX },
    highlighted: { type: Number, default: KEYWORDS_HIGHLIGHTED },
    placeholder: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});

const SEPARATORS = /[,;\n]/;
const draft = ref('');
const field = ref(null);
const dragFrom = ref(null);

function add(text) {
    const next = [...keywords.value];

    for (const word of text.split(SEPARATORS).map((w) => w.trim().toLowerCase()).filter(Boolean)) {
        if (!next.includes(word) && next.length < props.max) {
            next.push(word);
        }
    }

    if (next.length !== keywords.value.length) {
        keywords.value = next;
    }
}

// Typing or pasting "a, b, c" adds the complete words and keeps the unfinished tail in the field.
function onInput(event) {
    const value = event.target.value;

    if (SEPARATORS.test(value)) {
        const parts = value.split(SEPARATORS);
        add(parts.slice(0, -1).join(','));
        draft.value = parts.at(-1);
    } else {
        draft.value = value;
    }
}

function commit() {
    add(draft.value);
    draft.value = '';
}

function onBackspace() {
    if (!draft.value && keywords.value.length) {
        keywords.value = keywords.value.slice(0, -1);
    }
}

function remove(index) {
    keywords.value = keywords.value.filter((_, i) => i !== index);
}

function onDragStart(index, event) {
    dragFrom.value = index;
    event.dataTransfer.effectAllowed = 'move';
}

function onDrop(index) {
    const from = dragFrom.value;
    dragFrom.value = null;

    if (from === null || from === index) {
        return;
    }

    const next = [...keywords.value];
    const [moved] = next.splice(from, 1);
    next.splice(index, 0, moved);
    keywords.value = next;
}
</script>

<template>
    <div class="keyword-input" :class="{ 'is-disabled': disabled }" @click="field?.focus()">
        <el-tag
            v-for="(word, index) in keywords"
            :key="word"
            type="info"
            :effect="index < highlighted ? 'light' : 'plain'"
            :closable="!disabled"
            disable-transitions
            :draggable="!disabled"
            class="keyword-input__tag"
            :class="{ 'is-top': index < highlighted, 'is-dragging': index === dragFrom }"
            @dragstart="onDragStart(index, $event)"
            @dragover.prevent
            @drop.prevent.stop="onDrop(index)"
            @dragend="dragFrom = null"
            @close="remove(index)"
        >
            {{ word }}
        </el-tag>
        <input
            ref="field"
            :value="draft"
            :placeholder="keywords.length < max ? placeholder : ''"
            :disabled="disabled || keywords.length >= max"
            class="keyword-input__field"
            @input="onInput"
            @keydown.enter.prevent="commit"
            @keydown.backspace="onBackspace"
            @blur="commit"
        >
    </div>
</template>

<style scoped lang="scss">
.keyword-input {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    width: 100%;
    min-height: 32px;
    padding: 4px;
    line-height: normal;
    cursor: text;
    background: var(--el-fill-color-blank);
    border-radius: var(--el-border-radius-base);
    box-shadow: 0 0 0 1px var(--el-border-color) inset;
    transition: box-shadow var(--el-transition-duration);

    &:hover {
        box-shadow: 0 0 0 1px var(--el-border-color-hover) inset;
    }

    &:focus-within {
        box-shadow: 0 0 0 1px var(--el-color-primary) inset;
    }

    &.is-disabled {
        cursor: not-allowed;
        background: var(--el-disabled-bg-color);
        box-shadow: 0 0 0 1px var(--el-disabled-border-color) inset;

        .keyword-input__tag {
            cursor: default;
        }
    }
}

.keyword-input__tag {
    cursor: grab;

    &.is-top {
        font-weight: 500;
        color: var(--el-text-color-primary);
    }

    &.is-dragging {
        opacity: 0.4;
    }
}

.keyword-input__field {
    flex: 1;
    min-width: 120px;
    height: 24px;
    padding: 0 4px;
    font-size: var(--el-font-size-small);
    color: var(--el-text-color-regular);
    background: transparent;
    border: 0;
    outline: 0;

    &::placeholder {
        color: var(--el-text-color-placeholder);
    }
}
</style>
