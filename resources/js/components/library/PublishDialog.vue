<script setup>
import { computed, ref, watch } from 'vue';
import { getActiveLanguage } from 'laravel-vue-i18n';
import { ChevronDown, CircleCheck, TriangleAlert } from 'lucide-vue-next';
import MarketplaceLogo from '@/components/settings/MarketplaceLogo.vue';
import api from '@/core/api';
import { LISTINGS_BATCH_API_URL } from '@/config/constant';
import { formatNumberParams } from '@/utils/format';
import { batchRows, defaultSelection, nextSteps, selectionTotals } from '@/utils/publishing';

const props = defineProps({
    // Connected marketplace accounts (MarketplaceAccountResource)
    accounts: { type: Array, default: () => [] },
    // The library's works, to name the ones with warnings
    assets: { type: Array, default: () => [] },
});

const emit = defineEmits(['published']);

const open = defineModel({ type: Boolean, default: false });

// 'loading' | 'ready' | 'sending' | 'done' | 'error'
const stage = ref('loading');
const summary = ref(null);
const selected = ref([]);
// Accounts whose warnings are unfolded
const unfolded = ref(new Set());
// { [account id]: listings queued }, after sending
const queued = ref({});

const rows = computed(() => (summary.value ? batchRows(summary.value, props.accounts) : []));
const totals = computed(() => selectionTotals(rows.value, selected.value));
const steps = computed(() => nextSteps(rows.value, queued.value));
const queuedTotal = computed(() => Object.values(queued.value).reduce((sum, count) => sum + count, 0));

const workName = (id) => {
    const asset = props.assets.find((a) => a.id === id);

    return asset ? asset.title || asset.original_filename : id;
};

const warningText = (warning, translate) => translate(`library.checks.${warning.key}`, formatNumberParams(warning.params, getActiveLanguage()));

// Fresh numbers on every opening: works may have changed since the last one.
watch(open, async (isOpen) => {
    if (!isOpen) {
        return;
    }

    stage.value = 'loading';
    unfolded.value = new Set();
    queued.value = {};

    try {
        const { data } = await api.get(LISTINGS_BATCH_API_URL);
        summary.value = data;
        selected.value = defaultSelection(batchRows(data, props.accounts));
        stage.value = 'ready';
    } catch {
        // Reported by the API client.
        stage.value = 'error';
    }
}, { immediate: true });

function toggle(accountId) {
    const next = new Set(unfolded.value);

    if (next.has(accountId)) {
        next.delete(accountId);
    } else {
        next.add(accountId);
    }

    unfolded.value = next;
}

async function send() {
    stage.value = 'sending';

    try {
        const { data } = await api.post(LISTINGS_BATCH_API_URL, { accounts: selected.value });
        queued.value = data.queued;
        stage.value = 'done';
        emit('published', data);
    } catch {
        stage.value = 'ready';
    }
}
</script>

<template>
    <el-dialog v-model="open" :title="$t('library.publishing.dialog.title')" width="560" align-center class="publish-dialog">
        <div v-if="stage === 'loading'" v-loading="true" class="h-40" />

        <p v-else-if="stage === 'error'" class="text-[var(--el-text-color-secondary)]">{{ $t('library.publishing.dialog.load_failed') }}</p>

        <template v-else-if="stage === 'done'">
            <div class="flex items-start gap-3">
                <CircleCheck :size="22" :stroke-width="1.75" class="mt-0.5 flex-none text-[var(--el-color-success)]" />
                <div class="min-w-0">
                    <p class="font-medium">{{ $tChoice('library.publishing.dialog.queued', queuedTotal, { count: queuedTotal }) }}</p>
                    <p class="mt-1 text-[13px] leading-[18px] text-[var(--el-text-color-secondary)]">{{ $t('library.publishing.dialog.queued_hint') }}</p>
                </div>
            </div>

            <div v-if="steps.length" class="mt-5">
                <p class="mb-2 font-medium">{{ $t('library.publishing.dialog.next_title') }}</p>
                <ul class="flex flex-col gap-2">
                    <li v-for="step in steps" :key="step.account" class="text-[13px] leading-[18px]">
                        <span class="font-medium">{{ step.name }}:</span>
                        {{ $t(step.csv ? 'library.publishing.dialog.next_csv' : 'library.publishing.dialog.next_submit') }}
                    </li>
                </ul>
            </div>
        </template>

        <template v-else>
            <p class="text-[13px] leading-[18px] text-[var(--el-text-color-secondary)]">
                {{ $tChoice('library.publishing.dialog.intro', summary.works, { count: summary.works }) }}
            </p>

            <div class="mt-4 overflow-hidden rounded-lg border border-[var(--el-border-color-light)]">
                <div
                    v-for="row in rows"
                    :key="row.account"
                    class="border-t border-[var(--el-border-color-lighter)] px-3 py-2.5 first:border-t-0"
                    :class="{ 'opacity-60': !row.selectable }"
                >
                    <label class="flex cursor-pointer items-center gap-2.5" :class="{ '!cursor-default': !row.selectable }">
                        <el-checkbox v-model="selected" :value="row.account" :disabled="!row.selectable || stage === 'sending'" />
                        <MarketplaceLogo :name="row.connection.marketplace.name" :logo-url="row.connection.marketplace.logo_url" :size="28" />
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="truncate font-medium">{{ row.connection.label || row.connection.marketplace.name }}</span>
                            <span class="text-[13px] leading-[18px] text-[var(--el-text-color-secondary)]">
                                <template v-if="row.blocked">{{ $t(`library.checks.${row.blocked}`) }}</template>
                                <template v-else>
                                    {{ row.works ? $tChoice('library.publishing.dialog.works', row.works, { count: row.works }) : $t('library.publishing.dialog.nothing') }}
                                    <template v-if="row.unsupported">· {{ $tChoice('library.publishing.dialog.unsupported', row.unsupported, { count: row.unsupported }) }}</template>
                                </template>
                            </span>
                        </span>
                        <button
                            v-if="row.warnings.length"
                            type="button"
                            class="flex flex-none items-center gap-1 text-[13px] text-[var(--el-color-warning-dark-2)]"
                            @click.prevent="toggle(row.account)"
                        >
                            <TriangleAlert :size="14" :stroke-width="1.75" />
                            {{ $tChoice('library.publishing.dialog.warnings', row.warnings.length, { count: row.warnings.length }) }}
                            <ChevronDown :size="14" :stroke-width="1.75" :class="{ 'rotate-180': unfolded.has(row.account) }" />
                        </button>
                    </label>

                    <ul v-if="unfolded.has(row.account)" class="ml-[66px] mt-2 flex flex-col gap-2">
                        <li v-for="item in row.warnings" :key="item.asset" class="text-[13px] leading-[18px]">
                            <span class="block truncate font-medium">{{ workName(item.asset) }}</span>
                            <span v-for="warning in item.warnings" :key="warning.key" class="block text-[var(--el-text-color-secondary)]">
                                {{ warningText(warning, $t) }}
                            </span>
                        </li>
                    </ul>
                </div>
            </div>

            <p v-if="totals.warned" class="mt-3 text-[13px] leading-[18px] text-[var(--el-text-color-secondary)]">
                {{ $tChoice('library.publishing.dialog.warned_hint', totals.warned, { count: totals.warned }) }}
            </p>
        </template>

        <template #footer>
            <template v-if="stage === 'done' || stage === 'error'">
                <el-button @click="open = false">{{ $t('common.actions.close') }}</el-button>
            </template>
            <template v-else>
                <el-button :disabled="stage === 'sending'" @click="open = false">{{ $t('common.actions.cancel') }}</el-button>
                <el-button type="danger" :loading="stage === 'sending'" :disabled="stage !== 'ready' && stage !== 'sending' || !totals.listings" @click="send">
                    {{ $tChoice('library.publishing.dialog.send', totals.listings, { count: totals.listings }) }}
                </el-button>
            </template>
        </template>
    </el-dialog>
</template>
