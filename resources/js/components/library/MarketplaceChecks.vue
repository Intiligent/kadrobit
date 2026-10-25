<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getActiveLanguage } from 'laravel-vue-i18n';
import { CircleCheck, CircleMinus, CircleX, Clock, Info, LoaderCircle, Plus, RefreshCw, Send, TriangleAlert } from 'lucide-vue-next';
import { checkAsset } from '@/utils/marketplaceRules';
import { formatDateTime, formatNumberParams } from '@/utils/format';
import { canCancel, canRetry, listingFor, listingState } from '@/utils/publishing';
import { SETTINGS_MARKETPLACES_URL } from '@/config/constant';

const props = defineProps({
    asset: { type: Object, required: true },
    accounts: { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
    // Listing ids with a cancel or retry request on its way
    busy: { type: Array, default: () => [] },
});

const emit = defineEmits(['cancel', 'retry']);

// How the delivery to an account is shown (library.publishing.status.*).
const STATE = {
    queued: { icon: Clock, class: 'text-[var(--el-text-color-secondary)]' },
    sending: { icon: LoaderCircle, class: 'text-[var(--el-color-primary)]', spin: true },
    sent: { icon: Send, class: 'text-[var(--el-color-success-dark-2)]' },
    outdated: { icon: TriangleAlert, class: 'text-[var(--el-color-warning-dark-2)]' },
    failed: { icon: CircleX, class: 'text-[var(--el-color-danger)]' },
    cancelled: { icon: CircleMinus, class: 'text-[var(--el-text-color-secondary)]' },
};

const LEVEL = {
    ok: { icon: CircleCheck, class: 'text-[var(--el-color-success-dark-2)]' },
    warning: { icon: TriangleAlert, class: 'text-[var(--el-color-warning-dark-2)]' },
    error: { icon: CircleX, class: 'text-[var(--el-color-danger)]' },
    info: { icon: Info, class: 'text-[var(--el-text-color-secondary)]' },
    off: { icon: CircleMinus, class: 'text-[var(--el-text-color-secondary)]' },
};

const rows = computed(() => props.accounts.map((account) => {
    const listing = listingFor(props.asset, account.id);

    return { account, result: checkAsset(props.asset, account), listing, state: listing ? listingState(listing) : null };
}));

// "3.1 MP" reads "3,1 Мп" in Russian.
const params = (item) => formatNumberParams(item.params, getActiveLanguage());

const initials = (name) => name.split(/\s+/).map((word) => word[0]).join('').slice(0, 2).toUpperCase();
</script>

<template>
    <div class="flex flex-col gap-3">
        <p class="text-[13px] leading-[18px] text-[var(--el-text-color-secondary)]">{{ $t('library.checks.intro') }}</p>

        <el-empty v-if="!accounts.length" :image-size="110" :description="$t('library.checks.none')">
            <Link :href="SETTINGS_MARKETPLACES_URL" class="inline-flex items-center gap-1 font-medium text-[var(--el-color-primary)] hover:underline">
                <Plus :size="15" :stroke-width="2" />{{ $t('library.checks.connect') }}
            </Link>
        </el-empty>

        <div v-else class="overflow-hidden rounded-lg border border-[var(--el-border-color-light)]">
            <div
                v-for="{ account, result, listing, state } in rows"
                :key="account.id"
                class="flex gap-2.5 border-t border-[var(--el-border-color-lighter)] p-3 first:border-t-0"
                :class="{ 'opacity-60': result.off }"
            >
                <el-avatar shape="square" :size="28" :src="account.marketplace.logo_url || undefined" class="flex-none !text-[10px] !font-semibold">
                    {{ initials(account.marketplace.name) }}
                </el-avatar>
                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span class="font-medium">{{ account.label || account.marketplace.name }}</span>
                    <span
                        v-for="item in result.items"
                        :key="item.key"
                        class="flex items-start gap-1.5 text-[13px] leading-[18px]"
                        :class="LEVEL[item.level].class"
                    >
                        <component :is="LEVEL[item.level].icon" :size="14" :stroke-width="1.75" class="mt-0.5 flex-none" />
                        <span>{{ item.choice === null ? $t(item.key, params(item)) : $tChoice(item.key, item.choice, params(item)) }}</span>
                    </span>

                    <!-- The delivery to this account, once the work went out with a batch. -->
                    <div v-if="listing" class="mt-1.5 flex items-start gap-2 border-t border-dashed border-[var(--el-border-color-lighter)] pt-1.5">
                        <span class="flex min-w-0 flex-1 items-start gap-1.5 text-[13px] leading-[18px]" :class="STATE[state].class">
                            <component :is="STATE[state].icon" :size="14" :stroke-width="1.75" class="mt-0.5 flex-none" :class="{ 'animate-spin': STATE[state].spin }" />
                            <span class="min-w-0">
                                {{ $t(`library.publishing.status.${state}`) }}<template v-if="listing.sent_at && (state === 'sent' || state === 'outdated')">, {{ formatDateTime(listing.sent_at, getActiveLanguage()) }}</template>
                                <span v-if="state === 'failed' && listing.error" class="block text-[var(--el-text-color-secondary)]">{{ $t(`library.publishing.errors.${listing.error}`) }}</span>
                                <span v-else-if="state === 'outdated'" class="block text-[var(--el-text-color-secondary)]">{{ $t('library.publishing.outdated_hint') }}</span>
                            </span>
                        </span>
                        <el-button
                            v-if="canCancel(listing)"
                            size="small"
                            text
                            :disabled="readonly"
                            :loading="busy.includes(listing.id)"
                            @click="emit('cancel', listing)"
                        >
                            {{ $t('library.publishing.cancel') }}
                        </el-button>
                        <el-button
                            v-else-if="canRetry(listing, asset)"
                            size="small"
                            text
                            type="primary"
                            :disabled="readonly"
                            :loading="busy.includes(listing.id)"
                            @click="emit('retry', listing)"
                        >
                            <RefreshCw :size="13" :stroke-width="2" class="mr-1" />{{ $t('library.publishing.retry') }}
                        </el-button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
