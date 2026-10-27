<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import AssetGrid from '@/components/library/AssetGrid.vue';
import AssetInfoPanel from '@/components/library/AssetInfoPanel.vue';
import AssetToolbar from '@/components/library/AssetToolbar.vue';
import LibraryExplorer from '@/components/library/LibraryExplorer.vue';
import PublishPopover from '@/components/library/PublishPopover.vue';
import SelectionPanel from '@/components/library/SelectionPanel.vue';
import UploadStatus from '@/components/library/UploadStatus.vue';
import { useAssetAutosave } from '@/composables/useAssetAutosave';
import { usePendingAssets } from '@/composables/usePendingAssets';
import { mergeAsset } from '@/utils/mergeAsset';
import { useAssetUpload } from '@/composables/useAssetUpload';
import { useFolders } from '@/composables/useFolders';
import api from '@/core/api';
import notify from '@/core/notify';
import { ASSETS_API_URL, LISTINGS_API_URL } from '@/config/constant';
import { readyCount } from '@/utils/publishing';
import { editedFields, markEdited } from '@/utils/generation';
import { selectWorks } from '@/utils/selection';
import { useStoredRef } from '@/utils/storage';
import { buildFolderTree, folderPath, subtreeIds } from '@/utils/folders';

defineOptions({ layout: AppLayout });

const props = defineProps({
    assets: { type: Array, required: true },
    // Flat list: [{ id, parent_id, name }]
    folders: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    // Category lists by marketplace code: { shutterstock: [{ id, name, media_types }] }
    categories: { type: Object, default: () => ({}) },
    // { accept, max_size_mb, max_batch_files, batches }
    upload: { type: Object, required: true },
    // { available, limits: { title, description }, batch }: metadata generation (docs/metadata-generation.md)
    generation: { type: Object, default: () => ({ available: false, limits: {} }) },
});

// Local working copy: later stages edit and add works in place.
const assets = ref(props.assets.map((asset) => ({ ...asset })));
watch(() => props.assets, (next) => {
    assets.value = next.map((asset) => ({ ...asset }));
});

// Folders change in place; every change brings the server's list.
const folderStore = useFolders(props.folders);
const folders = folderStore.folders;
watch(() => props.folders, (next) => {
    folders.value = next.map((folder) => ({ ...folder }));
});

// 'all', 'unsorted' or a folder id
const place = ref('all');
const type = ref('all');
// Status tab, remembered per browser.
const status = useStoredRef('library.status', 'all', { allowed: ['all', 'draft', 'ready', 'submitted', 'archived'] });
const query = ref('');
const sort = ref('newest');
// Remembered per browser.
const size = useStoredRef('library.tile-size', 'large', { allowed: ['large', 'small'] });
// Remembered per browser, like the tile size; a work that is gone (deleted, another account) is dropped.
const selectedId = useStoredRef('library.selected', null);

if (selectedId.value && !assets.value.some((a) => a.id === selectedId.value)) {
    selectedId.value = null;
}

const TYPES = ['photo', 'video', 'vector', 'illustration'];
const isLive = (asset) => asset.status !== 'archived';
const live = computed(() => assets.value.filter(isLive));

const tree = computed(() => buildFolderTree(folders.value, live.value));

const inPlace = computed(() => {
    if (place.value === 'all') {
        return () => true;
    }

    if (place.value === 'unsorted') {
        return (asset) => !asset.folder_id;
    }

    const ids = subtreeIds(folders.value, place.value);

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
    submitted: typedAssets.value.filter((a) => a.status === 'submitted').length,
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

const selected = computed(() => assets.value.find((a) => a.id === selectedId.value) ?? null);

// Several works picked with Cmd/Ctrl- or Shift-click (utils/selection.js): the selection panel instead of the info
// panel. One picked work is `selectedId`, as before; the anchor is where the next Shift-click spans from.
const picked = ref([]);
// The explorer is folding or unfolding: the grid keeps its layout meanwhile (AssetGrid.vue, `frozen`).
const explorerResizing = ref(false);
let anchor = null;

const selectedIds = computed(() => (picked.value.length ? picked.value : selectedId.value ? [selectedId.value] : []));
const pickedAssets = computed(() => picked.value.map((id) => assets.value.find((a) => a.id === id)).filter(Boolean));

function pick(ids) {
    picked.value = ids.length > 1 ? ids : [];
    selectedId.value = ids.length === 1 ? ids[0] : null;
}

function selectAsset({ id, toggle = false, range = false }) {
    const next = selectWorks(selectedIds.value, visible.value.map((a) => a.id), id, { toggle, range, anchor: anchor ?? selectedId.value });

    anchor = next.anchor;
    pick(next.ids);
}

// A filter or a search that hides picked works drops them: an action must not reach works the user cannot see.
watch(visible, (list) => {
    if (picked.value.length) {
        const shown = new Set(list.map((a) => a.id));

        pick(picked.value.filter((id) => shown.has(id)));
    }
});

function onSelectionKey(event) {
    if (event.key === 'Escape' && picked.value.length && !event.target.closest?.('input, textarea, [contenteditable]')) {
        pick([]);
    }
}

window.addEventListener('keydown', onSelectionKey);

// Fill the empty fields of the picked works in the background, as after an upload.
const filling = ref(false);

async function fillPicked() {
    const ids = picked.value;

    if (!ids.length || readonly.value || filling.value) {
        return;
    }

    filling.value = true;

    try {
        const { data } = await api.post(`${ASSETS_API_URL}/generations`, { assets: ids });

        notify.message.success({ message: transChoice('library.selection.fill_queued', data.queued, { count: data.queued }) });
        // The works come back marked as generating; the poll follows them until done.
        pending.refresh(ids);
    } catch {
        // Reported by the API client (429 with the monthly limit, 503 without a provider).
    } finally {
        filling.value = false;
    }
}

// Back on the page, the remembered card is brought into view.
onMounted(async () => {
    await nextTick();
    document.querySelector(`[data-asset-id="${selectedId.value}"]`)?.scrollIntoView({ block: 'nearest' });
});

// Breadcrumb: parent folders, then the current place.
const path = computed(() => folderPath(folders.value, place.value));
const title = computed(() => path.value.at(-1) ?? trans(`library.explorer.views.${place.value}`));

watch(place, () => {
    type.value = 'all';
});

// Editing: changes show at once and are saved in the background.

const page = usePage();
// The API takes edits only from a confirmed email.
const readonly = computed(() => !page.props.auth.user?.email_verified);

// The server's version of a work into the grid and panel (utils/mergeAsset.js): an older version is skipped,
// fields edited meanwhile, being sent or rejected keep their local value.
function applyServerAsset(saved, local = autosave.localFields(saved.id)) {
    const asset = assets.value.find((a) => a.id === saved.id);

    if (asset) {
        mergeAsset(asset, saved, local);
    }
}

const autosave = useAssetAutosave({ onSaved: applyServerAsset });

// Works whose metadata is being generated in the background (KB-44) or whose uploads to marketplaces are in the
// queue (KB-32) are refreshed when it is over.
const pending = usePendingAssets({ assets, merge: (saved) => applyServerAsset(saved) });

// Publishing: the ready works go out in one batch from the toolbar popover (docs/publishing.md).

const ready = computed(() => readyCount(assets.value, props.accounts));

// The batch is queued: fetch the ready works with their new listings, then the poll follows them until sent.
function onPublished() {
    pending.refresh(assets.value.filter((a) => a.status === 'ready').map((a) => a.id));
}

// Listing ids with a cancel or retry request on its way
const listingsBusy = ref([]);

async function changeListing(listing, action) {
    const assetId = selected.value?.id;

    if (!assetId || readonly.value || listingsBusy.value.includes(listing.id)) {
        return;
    }

    listingsBusy.value = [...listingsBusy.value, listing.id];

    try {
        const { data } = await api.post(`${LISTINGS_API_URL}/${listing.id}/${action}`);
        // Looked up again: the list may have been replaced while the request was on its way.
        const asset = assets.value.find((a) => a.id === assetId);

        if (asset) {
            asset.listings = asset.listings.map((l) => (l.id === data.id ? data : l));
        }
    } catch {
        // Reported by the API client (409 says why); a fresh copy shows the listing as it is now.
        pending.refresh([assetId]);
    } finally {
        listingsBusy.value = listingsBusy.value.filter((id) => id !== listing.id);
    }
}

// Folders: an inline name field in the tree for a new folder or a rename, deleting, moving folders and works.

// { mode: 'create', parentId, key } | { mode: 'rename', id, key } | null; a new key for every opening
const editing = ref(null);
const editState = ref({ error: null, saving: false });
let editKey = 0;

function startEditing(next) {
    editing.value = { ...next, key: ++editKey };
    editState.value = { error: null, saving: false };
}

async function submitName(name) {
    const current = editing.value;
    editState.value = { error: null, saving: true };

    const result = current.mode === 'create'
        ? await folderStore.create(name, current.parentId)
        : await folderStore.rename(current.id, name);

    // Another field may have been opened meanwhile.
    if (editing.value !== current) {
        return;
    }

    if (result.failed) {
        editState.value = { error: result.error, saving: false };

        return;
    }

    editing.value = null;
}

async function deleteFolder(node) {
    const ids = subtreeIds(folders.value, node.id);
    // Archived works too: they also end up without a folder, though the tree does not count them.
    const works = assets.value.filter((a) => ids.has(a.folder_id)).length;

    const confirmed = await notify.confirm({
        title: trans('library.folders.delete_confirm_title', { name: node.name }),
        message: works
            ? transChoice('library.folders.delete_confirm_works', works, { count: works })
            : trans('library.folders.delete_confirm'),
        confirmText: trans('library.folders.delete'),
        cancelText: trans('common.actions.cancel'),
    });

    if (!confirmed) {
        return;
    }

    const result = await folderStore.remove(node.id);

    if (result.failed) {
        return;
    }

    // The server left their works without a folder.
    for (const asset of assets.value) {
        if (ids.has(asset.folder_id)) {
            asset.folder_id = null;
        }
    }

    if (ids.has(place.value)) {
        place.value = 'all';
    }
}

const moveFolder = (id, placement) => folderStore.move(id, placement);

// Shown in the new folder at once; put back if the server refuses.
async function moveAssets(folderId, ids) {
    const moved = assets.value.filter((a) => ids.includes(a.id) && a.folder_id !== folderId);
    const previous = new Map(moved.map((a) => [a.id, a.folder_id]));

    if (!moved.length || readonly.value) {
        return;
    }

    moved.forEach((a) => {
        a.folder_id = folderId;
    });

    if (!(await folderStore.moveAssets(moved.map((a) => a.id), folderId))) {
        moved.forEach((a) => {
            a.folder_id = previous.get(a.id);
        });
    }
}

function update(patch, options = {}) {
    const asset = selected.value;

    if (!asset || readonly.value) {
        return;
    }

    Object.assign(asset, patch);
    asset.metadata_sources = markEdited(asset.metadata_sources, patch);
    autosave.queue(asset.id, patch, options);
}

// Regenerating or restoring the generated metadata of the open work (docs/metadata-generation.md).

// 'regenerate' | 'restore' while one runs
const generating = ref(null);
const generationVersion = ref(0);

async function changeMetadata(action, generationId = null) {
    const asset = selected.value;

    if (!asset || readonly.value || generating.value) {
        return;
    }

    // Edits still waiting for the autosave reach the server first: it has to know which fields are the user's.
    await autosave.flush(asset.id);

    const edited = editedFields(asset);

    if (edited.length) {
        const confirmed = await notify.confirm({
            title: trans(`library.generation.${action}_confirm_title`),
            message: trans('library.generation.replace_confirm', { fields: edited.map((field) => trans(`library.fields.${field}`)).join(', ') }),
            confirmText: trans(`library.generation.${action}_confirm`),
            cancelText: trans('common.actions.cancel'),
        });

        if (!confirmed) {
            return;
        }
    }

    generating.value = action;

    try {
        const url = action === 'regenerate'
            ? `${ASSETS_API_URL}/${asset.id}/generations`
            : `${ASSETS_API_URL}/${asset.id}/generations/${generationId}/apply`;
        const body = await api.post(url);

        // The server's fields win: the user asked to replace them.
        applyServerAsset(body.data, new Set());
        notify.message.success({ message: trans(`library.generation.${action}_done`) });
    } catch {
        // Reported by the API client (502, 429 with the limit).
    } finally {
        generating.value = null;
        generationVersion.value++;
    }
}

// Rotating the open work: its original is replaced on the server (AssetRotator), the response brings the new files.

// Degrees of the running rotation, otherwise null
const rotating = ref(null);

async function rotate(degrees) {
    const asset = selected.value;

    if (!asset || readonly.value || rotating.value !== null) {
        return;
    }

    rotating.value = degrees;

    try {
        const body = await api.post(`${ASSETS_API_URL}/${asset.id}/rotate`, { degrees });

        applyServerAsset(body.data);
        notify.message.success({ message: trans('library.tools.rotate_done') });
    } catch {
        // Reported by the API client (409 with the reason).
    } finally {
        rotating.value = null;
    }
}

// Archived works only (the API refuses others): the work and its files are gone for good.
const deleting = ref(false);

async function deleteAsset() {
    const asset = selected.value;

    if (!asset || asset.status !== 'archived' || readonly.value || deleting.value) {
        return;
    }

    const confirmed = await notify.confirm({
        key: 'delete-asset',
        title: trans('library.footer.delete_confirm_title'),
        message: trans('library.footer.delete_confirm', { file: asset.original_filename }),
        confirmText: trans('library.footer.delete'),
        cancelText: trans('common.actions.cancel'),
    });

    if (!confirmed) {
        return;
    }

    deleting.value = true;

    try {
        // The archive may still be on its way (or other edits): the server must have them before the delete.
        await autosave.flush(asset.id);

        if (autosave.state[asset.id] === 'error') {
            // Not saved, so the server may not see the work as archived; the footer tells why.
            return;
        }

        await api.delete(`${ASSETS_API_URL}/${asset.id}`);
    } catch {
        // Reported by the API client; the work and its edits stay.
        return;
    } finally {
        deleting.value = false;
    }

    autosave.forget(asset.id);
    assets.value = assets.value.filter((a) => a.id !== asset.id);

    if (selectedId.value === asset.id) {
        selectedId.value = null;
    }
}

// `confirm: false` (⌘-click on Archive) skips the question: archiving is undone with Restore.
async function setStatus(next, { confirm = true } = {}) {
    const asset = selected.value;

    if (next === 'archived' && confirm) {
        const confirmed = await notify.confirm({
            title: trans('library.footer.archive_confirm_title'),
            message: trans('library.footer.archive_confirm', { file: asset.original_filename }),
            confirmText: trans('library.footer.archive'),
            cancelText: trans('common.actions.cancel'),
        });

        if (!confirmed) {
            return;
        }
    }

    const previous = asset.status;

    update({ status: next }, {
        immediate: true,
        // Refused by the server: back to the saved status. Other failures keep it queued for the next save.
        onError: (error) => {
            if (error.status === 422) {
                asset.status = previous;
            }
        },
    });
}

// Uploading

const uploader = useAssetUpload({
    maxSizeMb: props.upload.max_size_mb,
    maxBatchFiles: props.upload.max_batch_files,
    // Works arrive one by one as the batch is processed; the grid filters and sorts them like the rest.
    onCompleted(asset) {
        if (!assets.value.some((a) => a.id === asset.id)) {
            assets.value.unshift({ ...asset });
        }
    },
});

// Leaving the page stops the uploads that are still running: ask first.
// Unsaved edits are sent at once; a closing tab may not wait for them, so it asks too.
const leaving = () => trans(uploader.summary.value.active ? 'library.upload.leave_confirm' : 'library.footer.leave_confirm');
const mustAsk = () => uploader.summary.value.active || autosave.unsaved.value;
const onBeforeUnload = (event) => {
    autosave.flushAll();

    if (mustAsk()) {
        event.preventDefault();
        event.returnValue = leaving();
    }
};
const stopVisitGuard = router.on('before', (event) => {
    autosave.flushAll();

    if (uploader.summary.value.active && !window.confirm(leaving())) {
        event.preventDefault();
    }
});

window.addEventListener('beforeunload', onBeforeUnload);

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
    window.removeEventListener('keydown', onSelectionKey);
    stopVisitGuard();
    uploader.dispose();
    autosave.dispose();
});

// Uploads still being processed after a page reload carry on in the list and the grid.
uploader.restore(props.upload.batches);

// Files dropped in a folder go into it.
const uploadFolder = computed(() => (place.value === 'all' || place.value === 'unsorted' ? null : place.value));

function addFiles(files) {
    // New works are drafts: make sure they are visible.
    if (status.value !== 'all' && status.value !== 'draft') {
        status.value = 'all';
    }

    uploader.add(files, { folderId: uploadFolder.value });
}
</script>

<template>
    <Head :title="$t('library.title')" />

    <LibraryExplorer
        v-model:place="place"
        :folders="tree"
        :flat="folders"
        :counts="placeCounts"
        :accounts="accounts"
        :readonly="readonly"
        :editing="editing"
        :edit-state="editState"
        @edit="startEditing"
        @submit="submitName"
        @cancel="editing = null"
        @delete="deleteFolder"
        @move-folder="moveFolder"
        @move-assets="moveAssets"
        @resizing="explorerResizing = $event"
    />

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
        >
            <template #actions>
                <PublishPopover :count="ready" :accounts="accounts" :disabled="readonly" @published="onPublished" />
                <UploadStatus
                    :items="uploader.items"
                    :summary="uploader.summary.value"
                    :interrupted="uploader.interrupted.value"
                    @retry="uploader.retry"
                    @remove="uploader.remove"
                    @clear="uploader.clearSettled"
                />
            </template>
        </AssetToolbar>

        <AssetGrid
            :assets="visible"
            :accounts="accounts"
            :selected-ids="selectedIds"
            :frozen="explorerResizing"
            :size="size"
            :accept="upload.accept"
            :empty-title="empty.title"
            :empty-text="empty.text"
            :draggable="!readonly"
            @select="selectAsset"
            @files="addFiles"
        />
    </main>

    <SelectionPanel
        v-if="pickedAssets.length > 1"
        :assets="pickedAssets"
        :generation="generation"
        :filling="filling"
        :readonly="readonly"
        @fill="fillPicked"
        @clear="pick([])"
    />

    <AssetInfoPanel
        v-else-if="selected"
        :asset="selected"
        :accounts="accounts"
        :categories="categories"
        :folders="tree"
        :save-state="autosave.state[selected.id] ?? null"
        :errors="autosave.errors[selected.id] ?? {}"
        :readonly="readonly"
        @update="update"
        @status="setStatus"
        :deleting="deleting"
        @delete="deleteAsset"
        :listings-busy="listingsBusy"
        @cancel-listing="changeListing($event, 'cancel')"
        @retry-listing="changeListing($event, 'retry')"
        :generation="generation"
        :generating="generating"
        :generation-version="generationVersion"
        @regenerate="changeMetadata('regenerate')"
        @restore="changeMetadata('restore', $event)"
        :rotating="rotating"
        @rotate="rotate"
        @close="selectedId = null"
    />

</template>
