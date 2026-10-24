<script setup>
import { computed, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { getActiveLanguage, trans } from 'laravel-vue-i18n';
import { Plus } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import CredentialsNotice from '@/components/settings/CredentialsNotice.vue';
import MarketplaceCatalog from '@/components/settings/MarketplaceCatalog.vue';
import MarketplaceConnectDrawer from '@/components/settings/MarketplaceConnectDrawer.vue';
import MarketplaceConnectionList from '@/components/settings/MarketplaceConnectionList.vue';
import api from '@/core/api';
import notify from '@/core/notify';
import { MARKETPLACE_ACCOUNTS_API_URL } from '@/config/constant';

defineOptions({ layout: [AppLayout, SettingsLayout] });

const props = defineProps({
    // MarketplaceConnectionResource[]
    accounts: { type: Array, required: true },
    // MarketplaceCatalogResource[]: every marketplace, connectable or not
    catalog: { type: Array, required: true },
});

// Local working copy: every change answers with the account, which replaces the local one.
const accounts = ref([...props.accounts]);
watch(() => props.accounts, (next) => {
    accounts.value = [...next];
});

const checking = ref(new Set());
const drawerOpen = ref(false);
const drawerTarget = ref(null);

const connectedCodes = computed(() => new Set(accounts.value.map((a) => a.marketplace.code)));
const available = computed(() => props.catalog.filter((m) => m.connectable && !connectedCodes.value.has(m.code)));
// Only web-only marketplaces: the note says they accept uploads through their websites alone.
const unsupported = computed(() => props.catalog.filter((m) => m.upload_protocol === 'web').map((m) => m.name));

const unsupportedText = computed(() => {
    const names = new Intl.ListFormat(getActiveLanguage(), { type: 'conjunction' }).format(unsupported.value);

    return trans('settings.marketplaces.unsupported', { names });
});

function replace(saved) {
    const index = accounts.value.findIndex((a) => a.id === saved.id);

    if (index >= 0) {
        accounts.value[index] = saved;
    } else {
        accounts.value.push(saved);
    }
}

function openDrawer(target = null) {
    drawerTarget.value = target;
    drawerOpen.value = true;
}

function onSaved(saved, isEdit) {
    replace(saved);
    notify.success({
        message: trans(isEdit ? 'settings.marketplaces.toasts.saved' : 'settings.marketplaces.toasts.connected', { label: saved.label }),
        duration: 3000,
    });
}

async function check(account) {
    checking.value = new Set(checking.value).add(account.id);

    try {
        const response = await api.post(`${MARKETPLACE_ACCOUNTS_API_URL}/${account.id}/check`);
        replace(response.data);

        const failed = response.data.last_error;
        notify[failed ? 'warning' : 'success']({
            message: trans(failed ? 'settings.marketplaces.toasts.still_failing' : 'settings.marketplaces.toasts.ok', { label: account.label }),
            duration: 3000,
        });
    } finally {
        const next = new Set(checking.value);
        next.delete(account.id);
        checking.value = next;
    }
}

async function toggle(account, on) {
    const response = await api.patch(`${MARKETPLACE_ACCOUNTS_API_URL}/${account.id}`, { status: on ? 'active' : 'disabled' });
    replace(response.data);
    notify.info({
        message: trans(on ? 'settings.marketplaces.toasts.turned_on' : 'settings.marketplaces.toasts.turned_off', { label: account.label }),
        duration: 3000,
    });
}

async function remove(account) {
    const confirmed = await notify.confirm({
        title: trans('settings.marketplaces.remove_confirm.title', { label: account.label }),
        message: trans('settings.marketplaces.remove_confirm.message', { name: account.marketplace.name }),
        confirmText: trans('settings.marketplaces.remove'),
        cancelText: trans('settings.marketplaces.submit.cancel'),
        type: 'warning',
    });

    if (!confirmed) {
        return;
    }

    await api.delete(`${MARKETPLACE_ACCOUNTS_API_URL}/${account.id}`);
    accounts.value = accounts.value.filter((a) => a.id !== account.id);
    drawerOpen.value = false;
    notify.success({ message: trans('settings.marketplaces.toasts.removed', { label: account.label }), duration: 3000 });
}
</script>

<template>
    <Head :title="$t('settings.marketplaces.title')" />

    <div class="flex flex-col gap-6">
        <div class="flex items-start gap-4">
            <div class="min-w-0 flex-1">
                <h1 class="text-[22px] font-semibold leading-[30px]">{{ $t('settings.marketplaces.title') }}</h1>
                <p class="mt-1 max-w-[560px] text-pretty text-[var(--el-text-color-secondary)]">{{ $t('settings.marketplaces.intro') }}</p>
            </div>
            <el-button type="primary" @click="openDrawer()">
                <Plus :size="15" :stroke-width="2" class="mr-1.5" />{{ $t('settings.marketplaces.add') }}
            </el-button>
        </div>

        <CredentialsNotice />

        <section class="flex flex-col gap-2.5">
            <h2 class="flex items-baseline gap-2 text-[15px] font-semibold leading-[22px]">
                {{ $t('settings.marketplaces.connected') }}
                <span class="font-normal text-[var(--el-text-color-placeholder)]">{{ accounts.length }}</span>
            </h2>

            <MarketplaceConnectionList
                v-if="accounts.length"
                :accounts="accounts"
                :checking="checking"
                @check="check"
                @toggle="toggle"
                @edit="(account, replacePassword) => openDrawer({ account, replacePassword })"
                @remove="remove"
            />
            <div v-else class="flex flex-col items-center gap-1 rounded-[10px] border border-dashed border-[var(--el-border-color)] p-7 text-center">
                <p class="font-semibold">{{ $t('settings.marketplaces.empty_title') }}</p>
                <p class="text-[var(--el-text-color-secondary)]">{{ $t('settings.marketplaces.empty') }}</p>
            </div>
        </section>

        <section v-if="available.length" class="flex flex-col gap-2.5">
            <h2 class="flex items-baseline gap-2 text-[15px] font-semibold leading-[22px]">
                {{ $t('settings.marketplaces.available') }}
                <span class="font-normal text-[var(--el-text-color-placeholder)]">{{ available.length }}</span>
            </h2>
            <MarketplaceCatalog :marketplaces="available" @connect="(code) => openDrawer({ code })" />
        </section>

        <p v-if="unsupported.length" class="text-xs leading-[18px] text-[var(--el-text-color-secondary)]">{{ unsupportedText }}</p>
    </div>

    <MarketplaceConnectDrawer
        v-model="drawerOpen"
        :catalog="catalog"
        :accounts="accounts"
        :target="drawerTarget"
        @saved="onSaved"
        @remove="remove"
    />
</template>
