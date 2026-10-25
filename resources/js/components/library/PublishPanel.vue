<script setup>
import { computed, h, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { ChevronDown, X } from 'lucide-vue-next';
import PublishWorkRow from '@/components/library/PublishWorkRow.vue';
import api from '@/core/api';
import notify from '@/core/notify';
import { LISTINGS_BATCH_API_URL, SETTINGS_MARKETPLACES_URL } from '@/config/constant';
import { batchAccounts, defaultAccounts, firstWorks, nextSteps, pairKey, publishTotals, workRows } from '@/utils/publishing';

const props = defineProps({
    // Connected marketplace accounts (MarketplaceAccountResource)
    accounts: { type: Array, default: () => [] },
});

const emit = defineEmits(['published', 'close', 'busy']);

// 'loading' | 'ready' | 'sending' | 'error'
const stage = ref('loading');
const summary = ref(null);
const picked = ref([]);
const works = ref(new Set());
const skipped = ref(new Set());
const expanded = ref(new Set());
const accountsOpen = ref(false);

const connections = computed(() => Object.fromEntries(props.accounts.map((account) => [account.id, account])));
const accountRows = computed(() => (summary.value ? batchAccounts(summary.value, props.accounts) : []));
// An account disconnected while the popover is open drops out: its cards would have nothing to show.
const pickedConnected = computed(() => picked.value.filter((id) => connections.value[id]));
// Cards show on the picked accounts and on those turned off or signed out: those say why the work waits.
const shownAccounts = computed(() => [...pickedConnected.value, ...accountRows.value.filter((row) => row.blocked).map((row) => row.account)]);
const rows = computed(() => (summary.value ? workRows(summary.value, shownAccounts.value, works.value, skipped.value) : []));
const totals = computed(() => publishTotals(rows.value));

const selectable = computed(() => rows.value.filter((row) => row.selectable));
const chosenCount = computed(() => selectable.value.filter((row) => row.chosen).length);
// Works one send takes (publishing.batch_works); beyond it the rest waits for the next send.
const limit = computed(() => summary.value?.limit ?? Infinity);
const full = computed(() => chosenCount.value >= limit.value);
const allChosen = computed(() => chosenCount.value > 0 && chosenCount.value === Math.min(selectable.value.length, limit.value));
const allExpanded = computed(() => rows.value.length > 0 && rows.value.every((row) => expanded.value.has(row.item.asset)));

const stats = computed(() => {
    const { ok, warning, error } = totals.value;
    const parts = [
        ok && { key: 'ok', text: trans('library.publishing.popover.stats.ok', { count: ok }), class: 'text-[var(--el-color-success-dark-2)]' },
        warning && { key: 'warning', text: transChoice('library.publishing.popover.stats.warning', warning, { count: warning }), class: 'text-[var(--el-color-warning-dark-2)]' },
        error && { key: 'error', text: transChoice('library.publishing.popover.stats.error', error, { count: error }), class: 'text-[var(--el-color-danger)]' },
    ].filter(Boolean);

    return parts.length ? parts : [{ key: 'none', text: trans('library.publishing.popover.stats.none'), class: 'text-[var(--el-text-color-secondary)]' }];
});

const toggleIn = (set, key) => {
    const next = new Set(set);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    return next;
};

function toggleAccount(account) {
    picked.value = picked.value.includes(account) ? picked.value.filter((id) => id !== account) : [...picked.value, account];
}

function toggleAll() {
    works.value = allChosen.value ? new Set() : firstWorks(summary.value, pickedConnected.value);
}

function toggleExpandAll() {
    expanded.value = allExpanded.value ? new Set() : new Set(rows.value.map((row) => row.item.asset));
}

const accountNote = (row) => {
    if (row.blocked === 'invalid_credentials') {
        return { text: trans('library.publishing.popover.account_invalid'), class: 'text-[var(--el-color-danger)]' };
    }

    if (row.blocked) {
        return { text: trans('library.publishing.popover.account_disabled'), class: 'text-[var(--el-text-color-placeholder)]' };
    }

    if (!row.works && row.unsupported) {
        return { text: trans('library.publishing.popover.account_rejected'), class: 'text-[var(--el-color-danger)]' };
    }

    return row.works ? null : { text: trans('library.publishing.popover.account_done'), class: 'text-[var(--el-text-color-placeholder)]' };
};

// Mounted on every opening (PublishPopover.vue): the numbers are always fresh.
onMounted(async () => {
    window.addEventListener('keydown', onKeydown);

    try {
        const { data } = await api.get(LISTINGS_BATCH_API_URL);
        summary.value = data;
        picked.value = defaultAccounts(batchAccounts(data, props.accounts));
        works.value = firstWorks(data, picked.value);
        // The first work opens, so the per-marketplace breakdown is seen at once.
        expanded.value = new Set(data.items.slice(0, 1).map((item) => item.asset));
        stage.value = 'ready';
    } catch {
        // Reported by the API client.
        stage.value = 'error';
    }
});

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

function onKeydown(event) {
    if (event.key === 'Escape' && stage.value !== 'sending') {
        emit('close');
    }
}

async function send() {
    if (stage.value !== 'ready' || !totals.value.pairs.length) {
        return;
    }

    stage.value = 'sending';
    emit('busy', true);

    try {
        const { data } = await api.post(LISTINGS_BATCH_API_URL, { pairs: totals.value.pairs });
        const queued = Object.values(data.queued).reduce((sum, count) => sum + count, 0);
        const steps = nextSteps(accountRows.value, data.queued);

        notify.success({
            title: transChoice('library.publishing.popover.queued', queued, { count: queued }),
            message: h('div', { class: 'flex flex-col gap-1' }, [
                h('p', trans('library.publishing.popover.queued_hint')),
                ...steps.map((step) => h('p', trans(step.csv ? 'library.publishing.popover.next_csv' : 'library.publishing.popover.next_submit', { name: step.name }))),
            ]),
            duration: 8000,
        });
        emit('published', data);
    } catch {
        stage.value = 'ready';
        emit('busy', false);
    }
}
</script>

<template>
    <div class="flex max-h-[calc(100vh-96px)] flex-col text-[13px] leading-5 text-[var(--el-text-color-primary)]">
        <header class="flex flex-none items-start gap-3 px-5 pb-3.5 pt-4">
            <div class="min-w-0 flex-1">
                <p class="text-[17px] font-semibold leading-6">{{ $t('library.publishing.popover.title') }}</p>
                <p class="mt-0.5 text-[var(--el-text-color-secondary)]">{{ $t('library.publishing.popover.intro') }}</p>
            </div>
            <el-tooltip :content="$t('library.publishing.popover.close')" placement="top">
                <el-button text circle :disabled="stage === 'sending'" @click="emit('close')"><X :size="15" :stroke-width="2" /></el-button>
            </el-tooltip>
        </header>

        <div v-if="stage === 'loading'" v-loading="true" class="h-40" />

        <p v-else-if="stage === 'error'" class="px-5 pb-5 text-[var(--el-text-color-secondary)]">{{ $t('library.publishing.popover.load_failed') }}</p>

        <p v-else-if="!rows.length" class="px-5 pb-5 text-[var(--el-text-color-secondary)]">{{ $t('library.publishing.popover.empty') }}</p>

        <template v-else>
            <div class="flex flex-none flex-col gap-1.5 px-5 pb-3">
                <div class="flex items-center gap-1.5 text-[var(--el-text-color-secondary)]">
                    {{ $t('library.publishing.popover.to') }}
                    <button type="button" class="publish-panel__link" :aria-expanded="accountsOpen" @click="accountsOpen = !accountsOpen">
                        {{ $tChoice('library.publishing.popover.to_count', pickedConnected.length, { count: pickedConnected.length }) }}
                        <ChevronDown :size="14" :stroke-width="2" class="transition-transform" :class="{ 'rotate-180': accountsOpen }" />
                    </button>
                </div>

                <div v-if="accountsOpen" class="flex flex-wrap items-center gap-x-[18px] gap-y-1 pt-0.5">
                    <el-checkbox
                        v-for="row in accountRows"
                        :key="row.account"
                        :model-value="picked.includes(row.account)"
                        :disabled="!row.selectable || stage === 'sending'"
                        @update:model-value="toggleAccount(row.account)"
                    >
                        {{ row.connection.label || row.connection.marketplace.name }}
                        <span v-if="accountNote(row)" class="ml-1 text-xs" :class="accountNote(row).class">{{ accountNote(row).text }}</span>
                    </el-checkbox>
                    <Link :href="SETTINGS_MARKETPLACES_URL" class="text-xs font-medium text-[var(--el-color-primary)] hover:underline">
                        {{ $t('library.publishing.popover.manage') }}
                    </Link>
                </div>
            </div>

            <div class="flex h-9 flex-none items-center gap-3 border-t border-[var(--el-border-color-light)] bg-[var(--el-fill-color-extra-light)] px-5">
                <el-checkbox
                    :model-value="allChosen"
                    :indeterminate="chosenCount > 0 && !allChosen"
                    :disabled="stage === 'sending' || !selectable.length"
                    @update:model-value="toggleAll"
                />
                <span class="flex-1 text-xs font-semibold text-[var(--el-text-color-secondary)]">
                    {{ allChosen && selectable.length <= limit
                        ? $t('library.publishing.popover.works', { count: selectable.length })
                        : $t('library.publishing.popover.works_some', { selected: chosenCount, total: selectable.length }) }}
                </span>
                <button type="button" class="publish-panel__link text-xs" @click="toggleExpandAll">
                    {{ $t(allExpanded ? 'library.publishing.popover.collapse_all' : 'library.publishing.popover.expand_all') }}
                </button>
            </div>

            <p v-if="selectable.length > limit" class="flex-none border-t border-[var(--el-border-color-lighter)] px-5 py-2 text-xs text-[var(--el-text-color-secondary)]">
                {{ $tChoice('library.publishing.popover.limit', limit, { max: limit }) }}
            </p>

            <div class="min-h-0 flex-1 overflow-y-auto">
                <PublishWorkRow
                    v-for="row in rows"
                    :key="row.item.asset"
                    :row="row"
                    :connections="connections"
                    :expanded="expanded.has(row.item.asset)"
                    :disabled="stage === 'sending'"
                    :locked="full && !row.chosen"
                    @toggle="works = toggleIn(works, row.item.asset)"
                    @expand="expanded = toggleIn(expanded, row.item.asset)"
                    @pair="(account) => (skipped = toggleIn(skipped, pairKey(row.item.asset, account)))"
                />
            </div>

            <footer class="flex flex-none items-center gap-4 border-t border-[var(--el-border-color-light)] px-5 py-3">
                <p class="flex min-w-0 flex-1 items-center gap-1.5 overflow-hidden whitespace-nowrap text-xs font-medium">
                    <template v-for="(part, index) in stats" :key="part.key">
                        <span v-if="index" class="text-[var(--el-text-color-placeholder)]">/</span>
                        <span :class="part.class">{{ part.text }}</span>
                    </template>
                </p>
                <el-button :disabled="stage === 'sending'" @click="emit('close')">{{ $t('common.actions.cancel') }}</el-button>
                <!-- The footer's gap spaces the buttons: no Element Plus `.el-button + .el-button` margin on top of it. -->
                <el-button type="primary" class="!ml-0" :loading="stage === 'sending'" :disabled="!totals.pairs.length" @click="send">
                    {{ $t('library.publishing.popover.send') }}
                </el-button>
            </footer>
        </template>
    </div>
</template>

<style scoped lang="scss">
.publish-panel__link {
    display: inline-flex;
    gap: 2px;
    align-items: center;
    font-weight: 500;
    color: var(--el-color-primary);
    cursor: pointer;
    background: transparent;

    &:hover {
        text-decoration: underline;
    }
}
</style>
