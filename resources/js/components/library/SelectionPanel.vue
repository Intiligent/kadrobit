<script setup>
import { computed, ref } from 'vue';
import { Film, Image, PenTool, Sparkles, Tag, X } from 'lucide-vue-next';
import TagSelect from '@/components/library/TagSelect.vue';

const props = defineProps({
    // The picked works, in the grid's order
    assets: { type: Array, required: true },
    // { available, batch } from the page (docs/metadata-generation.md)
    generation: { type: Object, default: () => ({ available: false }) },
    // The fill request is running
    filling: { type: Boolean, default: false },
    // The user's tags to choose from: [{ id, name }]
    tags: { type: Array, default: () => [] },
    // The tagging request is running
    tagging: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['fill', 'tag', 'clear']);

// Tags to add to every picked work; the page clears them once added (`done`).
const adding = ref([]);

const addTags = () => emit('tag', adding.value, () => {
    adding.value = [];
});

const TYPE_ICON = { photo: Image, video: Film, vector: PenTool, illustration: Sparkles };

// Thumbnails of the first works, like a stack of the selection.
const PREVIEWS = 8;
const previews = computed(() => props.assets.slice(0, PREVIEWS));

// One request fills at most `generation.batch` works (POST /api/assets/generations).
const tooMany = computed(() => props.assets.length > props.generation.batch);
</script>

<template>
    <aside class="selection-panel">
        <header class="flex flex-none items-center gap-3 px-5 pb-3 pt-4">
            <div class="min-w-0 flex-1">
                <p class="font-semibold leading-5">{{ $tChoice('library.selection.title', assets.length, { count: assets.length }) }}</p>
                <p class="mt-1 text-[13px] leading-[18px] text-[var(--el-text-color-secondary)]">{{ $t('library.selection.hint') }}</p>
            </div>
            <button type="button" class="selection-panel__close" :aria-label="$t('library.selection.clear')" @click="emit('clear')">
                <X :size="18" :stroke-width="2" />
            </button>
        </header>

        <div class="grid grid-cols-4 gap-1.5 px-5">
            <div v-for="asset in previews" :key="asset.id" class="selection-panel__thumb">
                <el-image v-if="asset.thumbnail_url" :src="asset.thumbnail_url" fit="cover" class="size-full" />
                <component :is="TYPE_ICON[asset.type]" v-else :size="16" :stroke-width="1.75" />
            </div>
        </div>
        <p v-if="assets.length > PREVIEWS" class="mt-1.5 px-5 text-xs text-[var(--el-text-color-placeholder)]">
            {{ $tChoice('library.selection.more', assets.length - PREVIEWS, { count: assets.length - PREVIEWS }) }}
        </p>

        <section class="mx-5 mt-5 border-t border-[var(--el-border-color-lighter)] pt-4">
            <p class="font-medium">{{ $t('library.tags.label') }}</p>
            <p class="mt-0.5 text-[13px] leading-[18px] text-[var(--el-text-color-secondary)]">{{ $t('library.selection.tags_hint') }}</p>
            <TagSelect v-model="adding" :tags="tags" :disabled="readonly" class="mt-3" />
            <el-button class="mt-3" :loading="tagging" :disabled="readonly || !adding.length" @click="addTags">
                <Tag :size="15" :stroke-width="2" class="mr-1.5" />
                {{ $tChoice('library.selection.tags_add', assets.length, { count: assets.length }) }}
            </el-button>
        </section>

        <section v-if="generation.available" class="mx-5 mt-5 border-t border-[var(--el-border-color-lighter)] pt-4">
            <p class="font-medium">{{ $t('library.generation.title') }}</p>
            <p class="mt-0.5 text-[13px] leading-[18px] text-[var(--el-text-color-secondary)]">
                {{ tooMany ? $tChoice('library.selection.fill_too_many', generation.batch, { max: generation.batch }) : $t('library.selection.fill_hint') }}
            </p>
            <el-button class="mt-3" :loading="filling" :disabled="readonly || tooMany" @click="emit('fill')">
                <Sparkles :size="15" :stroke-width="2" class="mr-1.5" />
                {{ $tChoice('library.selection.fill', assets.length, { count: assets.length }) }}
            </el-button>
        </section>
    </aside>
</template>

<style scoped lang="scss">
// The same column as the info panel (AssetInfoPanel.vue).
.selection-panel {
    display: flex;
    flex: none;
    flex-direction: column;
    width: 416px;
    min-height: 0;
    background: var(--el-bg-color);
    border-left: 1px solid var(--el-border-color-light);
}

.selection-panel__thumb {
    display: flex;
    align-items: center;
    justify-content: center;
    aspect-ratio: 4 / 3;
    overflow: hidden;
    color: var(--el-text-color-placeholder);
    background: var(--el-fill-color-dark);
    border-radius: 6px;
}

.selection-panel__close {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    color: var(--el-text-color-secondary);
    cursor: pointer;
    background: transparent;
    border: 0;
    border-radius: 6px;

    &:hover {
        color: var(--el-text-color-primary);
        background: var(--el-fill-color);
    }
}
</style>
