<script setup>
import { computed, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import AssetGrid from '@/components/library/AssetGrid.vue';
import AssetToolbar from '@/components/library/AssetToolbar.vue';
import LibraryExplorer from '@/components/library/LibraryExplorer.vue';
import { buildFolderTree, folderPath, subtreeIds } from '@/utils/folders';

defineOptions({ layout: AppLayout });

const props = defineProps({
    assets: { type: Array, required: true },
    // Flat list: [{ id, parent_id, name }]
    folders: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
});

// Local working copy: later stages edit and add works in place.
const assets = ref(props.assets.map((asset) => ({ ...asset })));
watch(() => props.assets, (next) => {
    assets.value = next.map((asset) => ({ ...asset }));
});

// 'all', 'unsorted' or a folder id
const place = ref('all');
const type = ref('all');
const status = ref('all');
const query = ref('');
const sort = ref('newest');
const size = ref('large');
const selectedId = ref(null);

const TYPES = ['photo', 'video', 'vector', 'illustration'];
const isLive = (asset) => asset.status !== 'archived';
const live = computed(() => assets.value.filter(isLive));

const tree = computed(() => buildFolderTree(props.folders, live.value));

const inPlace = computed(() => {
    if (place.value === 'all') {
        return () => true;
    }

    if (place.value === 'unsorted') {
        return (asset) => !asset.folder_id;
    }

    const ids = subtreeIds(props.folders, place.value);

    return (asset) => ids.has(asset.folder_id);
});

const placeCounts = computed(() => ({
    all: live.value.length,
    unsorted: live.value.filter((asset) => !asset.folder_id).length,
}));

const placeAssets = computed(() => assets.value.filter(inPlace.value));

// Types offered by the type filter: those present here, and the chosen one so it can be cleared.
const types = computed(() => TYPES.filter((t) => t === type.value || placeAssets.value.some((a) => a.type === t)));

const typedAssets = computed(() => placeAssets.value.filter((a) => type.value === 'all' || a.type === type.value));

const statusCounts = computed(() => ({
    all: typedAssets.value.filter(isLive).length,
    draft: typedAssets.value.filter((a) => a.status === 'draft').length,
    ready: typedAssets.value.filter((a) => a.status === 'ready').length,
    archived: typedAssets.value.filter((a) => a.status === 'archived').length,
}));

const SORTERS = {
    newest: (a, b) => b.created_at.localeCompare(a.created_at),
    oldest: (a, b) => a.created_at.localeCompare(b.created_at),
    keywords: (a, b) => a.keywords.length - b.keywords.length,
    // Untitled works go last.
    title: (a, b) => (a.title || '￿').localeCompare(b.title || '￿'),
};

const visible = computed(() => {
    const q = query.value.trim().toLowerCase();

    return typedAssets.value
        .filter((a) => (status.value === 'all' ? isLive(a) : a.status === status.value))
        .filter((a) => !q || [a.title, a.description, a.original_filename, ...a.keywords].join(' ').toLowerCase().includes(q))
        .sort(SORTERS[sort.value]);
});

const empty = computed(() => {
    if (query.value.trim()) {
        return { title: trans('library.grid.empty_search', { query: query.value.trim() }), text: '' };
    }

    if (!assets.value.length) {
        return { title: trans('library.grid.empty_library_title'), text: trans('library.grid.empty_library') };
    }

    return { title: trans('library.grid.empty_filtered'), text: '' };
});

// Breadcrumb: parent folders, then the current place.
const path = computed(() => folderPath(props.folders, place.value));
const title = computed(() => path.value.at(-1) ?? trans(`library.explorer.views.${place.value}`));

watch(place, () => {
    type.value = 'all';
});

// TODO(KB-16): hand the files to the upload queue.
function addFiles() {}
</script>

<template>
    <Head :title="$t('library.title')" />

    <LibraryExplorer v-model:place="place" :folders="tree" :counts="placeCounts" :accounts="accounts" />

    <main class="flex min-w-0 flex-1 flex-col">
        <AssetToolbar
            v-model:query="query"
            v-model:sort="sort"
            v-model:size="size"
            v-model:status="status"
            v-model:type="type"
            :title="title"
            :path="path.slice(0, -1)"
            :total="statusCounts.all"
            :counts="statusCounts"
            :types="types"
        />

        <AssetGrid
            :assets="visible"
            :selected-id="selectedId"
            :size="size"
            :empty-title="empty.title"
            :empty-text="empty.text"
            @select="selectedId = $event"
            @files="addFiles"
        />
    </main>
</template>
