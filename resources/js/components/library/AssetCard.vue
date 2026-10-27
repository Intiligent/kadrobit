<script setup>
import { computed, ref } from 'vue';
import { Film, Image, LoaderCircle, PenTool, Play, Sparkles } from 'lucide-vue-next';
import { DRAG_ASSETS } from '@/config/constant';
import ListingBadges from '@/components/library/ListingBadges.vue';
import { formatDuration } from '@/utils/format';

const props = defineProps({
    asset: { type: Object, required: true },
    // Connected marketplace accounts, for the delivery badges
    accounts: { type: Array, default: () => [] },
    selected: { type: Boolean, default: false },
    captions: { type: Boolean, default: true },
    // Can be dragged onto a folder in the explorer.
    draggable: { type: Boolean, default: false },
    // Picked works: dragging one of them drags them all
    dragGroup: { type: Array, default: () => [] },
});

const emit = defineEmits(['select']);

const TYPE_ICON = { photo: Image, video: Film, vector: PenTool, illustration: Sparkles };

const keywordCount = computed(() => props.asset.keywords.length);

const dragging = ref(false);

// Cmd/Ctrl adds or removes the work, Shift adds the range up to it (utils/selection.js).
function select(event) {
    emit('select', { id: props.asset.id, toggle: event.metaKey || event.ctrlKey, range: event.shiftKey });
}

function onDragStart(event) {
    dragging.value = true;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData(DRAG_ASSETS, JSON.stringify(props.dragGroup.includes(props.asset.id) ? props.dragGroup : [props.asset.id]));
}
</script>

<template>
    <!-- A div, not a button: Firefox does not drag buttons. -->
    <div
        role="button"
        tabindex="0"
        class="asset-card select-none"
        :class="{ 'is-selected': selected, 'is-dragging': dragging }"
        :data-asset-id="asset.id"
        :draggable="draggable"
        @click="select"
        @keydown.enter.prevent="select"
        @keydown.space.prevent="select"
        @dragstart="onDragStart"
        @dragend="dragging = false"
    >
        <div class="asset-card__thumb">
            <!-- Native lazy loading (loading="lazy"; the lazy prop is a JS observer of its own).
                 The spinner stands in for the type icon while the file loads. -->
            <el-image v-if="asset.thumbnail_url" :src="asset.thumbnail_url" fit="cover" loading="lazy" class="size-full">
                <template #placeholder>
                    <div class="asset-card__placeholder"><LoaderCircle :size="28" :stroke-width="1.5" class="animate-spin" /></div>
                </template>
                <template #error>
                    <div class="asset-card__placeholder"><component :is="TYPE_ICON[asset.type]" :size="36" :stroke-width="1.5" /></div>
                </template>
            </el-image>
            <div v-else class="asset-card__placeholder">
                <component :is="TYPE_ICON[asset.type]" :size="36" :stroke-width="1.5" />
            </div>

            <span class="asset-card__status" :class="`is-${asset.status}`">{{ $t(`library.status.${asset.status}`) }}</span>

            <span v-if="asset.type === 'video' && asset.duration_ms" class="asset-card__duration">
                <Play :size="10" fill="currentColor" :stroke-width="0" />{{ formatDuration(asset.duration_ms) }}
            </span>

            <ListingBadges :listings="asset.listings" :accounts="accounts" />
        </div>

        <div v-if="captions" class="mt-2.5 min-w-0 text-left">
            <div class="truncate font-medium leading-5" :class="{ 'text-[var(--el-text-color-placeholder)]': !asset.title }">
                {{ asset.title || $t('library.card.untitled', { file: asset.original_filename }) }}
            </div>
            <div v-if="asset.metadata_pending" class="flex items-center gap-1.5 text-[13px] leading-5 text-[var(--el-text-color-secondary)]">
                <LoaderCircle :size="13" :stroke-width="2" class="flex-none animate-spin" />{{ $t('library.generation.pending') }}
            </div>
            <div
                v-else
                class="text-[13px] leading-5"
                :class="keywordCount ? 'text-[var(--el-text-color-secondary)]' : 'text-[var(--el-color-warning-dark-2)]'"
            >
                {{ keywordCount ? $tChoice('library.card.keywords', keywordCount, { count: keywordCount }) : $t('library.card.no_keywords') }}
            </div>
        </div>
    </div>
</template>

<style scoped lang="scss">
.asset-card {
    display: flex;
    flex-direction: column;
    min-width: 0;
    padding: 0;
    color: inherit;
    text-align: left;
    cursor: pointer;
    background: none;
    border: 0;
    outline: none;

    &.is-dragging {
        opacity: 0.5;
    }
}

.asset-card:focus-visible .asset-card__thumb {
    outline-color: var(--el-color-primary-light-5);
}

.asset-card__thumb {
    position: relative;
    aspect-ratio: 4 / 3;
    overflow: hidden;
    background: var(--el-fill-color-dark);
    border-radius: 8px;
    outline: 2px solid transparent;
    outline-offset: 2px;
    transition: outline-color var(--el-transition-duration-fast);

    .asset-card:hover & {
        outline-color: var(--el-border-color-dark);
    }

    .asset-card.is-selected & {
        outline-color: var(--el-color-primary);
    }
}

.asset-card__placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    color: var(--el-text-color-placeholder);
}

.asset-card__status {
    position: absolute;
    top: 8px;
    left: 8px;
    height: 21px;
    padding: 0 8px;
    font-size: 12px;
    font-weight: 500;
    line-height: 21px;
    color: var(--el-text-color-secondary);
    background: rgb(255 255 255 / 92%);
    border-radius: 11px;

    &.is-ready {
        color: var(--el-color-success-dark-2);
        background: var(--el-color-success-light-9);
    }

    &.is-submitted {
        color: var(--kb-color-submitted-dark-2);
        background: var(--kb-color-submitted-light-9);
    }

    &.is-archived {
        color: var(--el-color-white);
        background: var(--el-overlay-color-lighter);
    }
}

.asset-card__duration {
    position: absolute;
    bottom: 8px;
    left: 8px;
    display: flex;
    gap: 4px;
    align-items: center;
    height: 22px;
    padding: 0 7px;
    font-size: 12px;
    font-weight: 500;
    color: var(--el-color-white);
    background: var(--el-overlay-color-light);
    border-radius: 6px;
}
</style>
