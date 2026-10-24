<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { getActiveLanguage, trans } from 'laravel-vue-i18n';
import { ChevronLeft, Info, Lock, TriangleAlert, X } from 'lucide-vue-next';
import MarketplaceLogo from '@/components/settings/MarketplaceLogo.vue';
import api from '@/core/api';
import { nextAccountLabel } from '@/utils/marketplaceConnection';
import { MARKETPLACE_ACCOUNTS_API_URL } from '@/config/constant';

const open = defineModel({ type: Boolean, default: false });

const props = defineProps({
    catalog: { type: Array, required: true },
    accounts: { type: Array, default: () => [] },
    // What to open with: { code } to connect, { account, replacePassword } to edit, null to pick a marketplace
    target: { type: Object, default: null },
});

const emit = defineEmits(['saved', 'remove']);

const step = ref('pick');
const code = ref(null);
const fromPick = ref(false);
const form = reactive({ label: '', contributor_id: '', host: '', login: '', password: '' });
const hostEditing = ref(false);
const replacing = ref(false);
const submitting = ref(false);
const connectionError = ref('');
const fieldErrors = ref({});

const account = computed(() => props.target?.account ?? null);
const isEdit = computed(() => Boolean(account.value));
const marketplace = computed(() => props.catalog.find((m) => m.code === code.value) ?? null);
const connectable = computed(() => props.catalog.filter((m) => m.connectable));
const connectedCodes = computed(() => new Set(props.accounts.map((a) => a.marketplace.code)));
const protocol = computed(() => marketplace.value?.upload_protocol.toUpperCase() ?? '');
const hostLocked = computed(() => Boolean(marketplace.value?.default_host) && !hostEditing.value && (!isEdit.value || form.host === marketplace.value.default_host));
const showSavedPassword = computed(() => isEdit.value && account.value.has_password && !replacing.value);

const title = computed(() => {
    if (step.value === 'pick') {
        return trans('settings.marketplaces.drawer.pick_title');
    }

    return isEdit.value
        ? trans('settings.marketplaces.drawer.edit_title', { name: account.value.label })
        : trans('settings.marketplaces.drawer.connect_title', { name: marketplace.value.name });
});

const subtitle = computed(() => (step.value === 'pick'
    ? trans('settings.marketplaces.drawer.pick_subtitle')
    : trans('settings.marketplaces.drawer.subtitle', { protocol: protocol.value })));

const passwordDate = computed(() => (account.value?.credentials_changed_at
    ? new Intl.DateTimeFormat(getActiveLanguage(), { month: 'short', day: 'numeric' }).format(new Date(account.value.credentials_changed_at))
    : ''));

const submitLabel = computed(() => {
    if (submitting.value) {
        return trans('settings.marketplaces.submit.checking');
    }

    if (isEdit.value && !changesConnection()) {
        return trans('settings.marketplaces.submit.save_plain');
    }

    return trans(isEdit.value ? 'settings.marketplaces.submit.save' : 'settings.marketplaces.submit.connect');
});

// A second account on the same marketplace gets "Shutterstock 2" as its starting name.
function defaultLabel(item) {
    return nextAccountLabel(item.name, props.accounts.filter((a) => a.marketplace.code === item.code).map((a) => a.label));
}

function startForm(item, picked = false) {
    code.value = item.code;
    fromPick.value = picked;
    Object.assign(form, { label: defaultLabel(item), contributor_id: '', host: item.default_host ?? '', login: '', password: '' });
    hostEditing.value = false;
    replacing.value = false;
    step.value = 'form';
    resetErrors();
}

function startEdit(target) {
    const a = target.account;
    code.value = a.marketplace.code;
    fromPick.value = false;
    Object.assign(form, { label: a.label, contributor_id: a.contributor_id ?? '', host: a.host ?? '', login: a.login ?? '', password: '' });
    hostEditing.value = false;
    replacing.value = Boolean(target.replacePassword) || !a.has_password;
    step.value = 'form';
    resetErrors();
}

function resetErrors() {
    connectionError.value = '';
    fieldErrors.value = {};
}

watch(open, (value) => {
    if (!value) {
        return;
    }

    if (props.target?.account) {
        startEdit(props.target);
    } else if (props.target?.code) {
        startForm(props.catalog.find((m) => m.code === props.target.code));
    } else {
        step.value = 'pick';
    }
});

// Editing a field clears its error and the sign-in error: they described the old values.
watch(form, () => {
    if (connectionError.value || Object.keys(fieldErrors.value).length) {
        resetErrors();
    }
});

function changesConnection() {
    const a = account.value;

    return form.host !== (a.host ?? '') || form.login !== (a.login ?? '') || (replacing.value && form.password !== '');
}

function payload() {
    if (!isEdit.value) {
        return { marketplace: code.value, ...form, host: hostLocked.value ? '' : form.host };
    }

    const a = account.value;
    const data = {};

    for (const field of ['label', 'contributor_id', 'host', 'login']) {
        if (form[field] !== (a[field] ?? '')) {
            data[field] = form[field];
        }
    }

    if (replacing.value && form.password) {
        data.password = form.password;
    }

    return data;
}

async function submit() {
    if (submitting.value) {
        return;
    }

    submitting.value = true;
    resetErrors();

    try {
        const response = isEdit.value
            ? await api.patch(`${MARKETPLACE_ACCOUNTS_API_URL}/${account.value.id}`, payload(), { silent: [422] })
            : await api.post(MARKETPLACE_ACCOUNTS_API_URL, payload(), { silent: [422] });

        emit('saved', response.data, isEdit.value);
        open.value = false;
    } catch (error) {
        if (error.status === 422) {
            connectionError.value = error.fieldError?.('connection') ?? '';
            fieldErrors.value = Object.fromEntries(
                ['label', 'contributor_id', 'host', 'login', 'password'].map((f) => [f, error.fieldError?.(f) ?? '']),
            );
        }
    } finally {
        submitting.value = false;
    }
}

// The sign-in can take up to the server timeout; closing mid-check would hide its result.
const beforeClose = (done) => {
    if (!submitting.value) {
        done();
    }
};
</script>

<template>
    <el-drawer v-model="open" :size="460" :with-header="false" :before-close="beforeClose" class="connect-drawer">
        <div class="flex h-full flex-col">
            <header class="flex h-16 flex-none items-center gap-2.5 border-b border-[var(--el-border-color-light)] pl-5 pr-4">
                <el-button v-if="step === 'form' && fromPick" text circle class="-ml-1.5" :aria-label="$t('settings.marketplaces.drawer.back')" @click="step = 'pick'">
                    <ChevronLeft :size="16" :stroke-width="2" />
                </el-button>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-[15px] font-semibold leading-[22px]">{{ title }}</p>
                    <p class="text-xs leading-[18px] text-[var(--el-text-color-secondary)]">{{ subtitle }}</p>
                </div>
                <el-button text circle :disabled="submitting" @click="open = false"><X :size="16" :stroke-width="2" /></el-button>
            </header>

            <el-scrollbar class="flex-1">
                <div class="p-5">
                    <div v-if="step === 'pick'" class="flex flex-col gap-1.5">
                        <button
                            v-for="item in connectable"
                            :key="item.code"
                            type="button"
                            class="connect-drawer__option"
                            @click="startForm(item, true)"
                        >
                            <MarketplaceLogo :name="item.name" :logo-url="item.logo_url" :size="32" />
                            <span class="min-w-0 flex-1 text-left">
                                <span class="block font-semibold">{{ item.name }}</span>
                                <span class="block text-xs leading-[18px] text-[var(--el-text-color-secondary)]">{{ item.upload_protocol.toUpperCase() }}</span>
                            </span>
                            <span v-if="connectedCodes.has(item.code)" class="whitespace-nowrap text-xs font-medium text-[var(--el-color-success-dark-2)]">
                                {{ $t('settings.marketplaces.drawer.connected_add_another') }}
                            </span>
                        </button>
                    </div>

                    <el-form v-else label-position="top" class="connect-drawer__form" @submit.prevent="submit">
                        <el-alert v-if="marketplace.note" type="info" :closable="false" class="mb-5">
                            <template #icon><Info :size="15" :stroke-width="1.75" /></template>
                            {{ marketplace.note }}
                        </el-alert>

                        <el-form-item :label="$t('settings.marketplaces.fields.label')" :error="fieldErrors.label">
                            <el-input v-model="form.label" maxlength="60" />
                            <p class="connect-drawer__hint">{{ $t('settings.marketplaces.fields.label_hint') }}</p>
                        </el-form-item>

                        <el-form-item :error="fieldErrors.contributor_id">
                            <template #label>
                                <span class="flex w-full justify-between">
                                    {{ $t('settings.marketplaces.fields.contributor_id') }}
                                    <span class="text-xs font-normal text-[var(--el-text-color-placeholder)]">{{ $t('settings.marketplaces.fields.optional') }}</span>
                                </span>
                            </template>
                            <el-input v-model="form.contributor_id" maxlength="64" :placeholder="$t('settings.marketplaces.fields.contributor_id_placeholder')" />
                        </el-form-item>

                        <el-divider class="!mb-4 !mt-1" />
                        <p class="connect-drawer__section">{{ $t('settings.marketplaces.fields.sign_in') }}</p>

                        <el-form-item :label="$t('settings.marketplaces.fields.host')" :error="fieldErrors.host">
                            <el-input v-if="hostLocked" :model-value="marketplace.default_host" disabled>
                                <template #prepend>{{ protocol }}</template>
                                <template #append>
                                    <el-button @click="hostEditing = true">{{ $t('settings.marketplaces.fields.host_change') }}</el-button>
                                </template>
                            </el-input>
                            <el-input v-else v-model="form.host" spellcheck="false" :placeholder="protocol === 'SFTP' ? 'sftp.example.com' : 'ftp.example.com'">
                                <template #prepend>{{ protocol }}</template>
                            </el-input>
                        </el-form-item>

                        <el-form-item :label="$t('settings.marketplaces.fields.login')" :error="fieldErrors.login">
                            <el-input v-model="form.login" autocomplete="off" spellcheck="false" />
                        </el-form-item>

                        <el-form-item
                            :label="$t(marketplace.separate_password ? 'settings.marketplaces.fields.ftp_password' : 'settings.marketplaces.fields.password')"
                            :error="fieldErrors.password"
                        >
                            <el-input v-if="showSavedPassword" model-value="••••••••••" disabled>
                                <template #suffix>
                                    <span class="text-xs">{{ $t('settings.marketplaces.fields.password_saved', { date: passwordDate }) }}</span>
                                </template>
                                <template #append>
                                    <el-button @click="replacing = true">{{ $t('settings.marketplaces.fields.password_replace') }}</el-button>
                                </template>
                            </el-input>
                            <el-input
                                v-else
                                v-model="form.password"
                                type="password"
                                show-password
                                autocomplete="new-password"
                                :placeholder="isEdit ? $t('settings.marketplaces.fields.password_new') : ''"
                            />
                            <p class="connect-drawer__hint flex items-start gap-1.5">
                                <Lock :size="12" :stroke-width="2" class="mt-0.5 flex-none" />
                                {{ $t('settings.marketplaces.fields.password_hint') }}
                            </p>
                        </el-form-item>

                        <el-alert v-if="marketplace.upload_protocol === 'ftp'" type="warning" :closable="false" class="mb-4">
                            <template #icon><TriangleAlert :size="15" :stroke-width="1.75" /></template>
                            <span class="font-semibold">{{ $t('settings.marketplaces.fields.plain_ftp_title', { name: marketplace.name }) }}</span>
                            {{ $t('settings.marketplaces.fields.plain_ftp') }}
                        </el-alert>

                        <el-alert v-if="connectionError" type="error" :closable="false" show-icon :title="connectionError" />
                    </el-form>
                </div>
            </el-scrollbar>

            <footer v-if="step === 'form'" class="flex flex-none items-center gap-2 border-t border-[var(--el-border-color-light)] px-5 py-3">
                <el-button v-if="isEdit" type="danger" text class="-ml-2" :disabled="submitting" @click="emit('remove', account)">
                    {{ $t('settings.marketplaces.remove') }}
                </el-button>
                <div class="flex-1" />
                <el-button :disabled="submitting" @click="open = false">{{ $t('settings.marketplaces.submit.cancel') }}</el-button>
                <el-button type="primary" :loading="submitting" @click="submit">{{ submitLabel }}</el-button>
            </footer>
        </div>
    </el-drawer>
</template>

<style scoped lang="scss">
.connect-drawer__option {
    display: flex;
    gap: 12px;
    align-items: center;
    width: 100%;
    padding: 10px 12px;
    color: inherit;
    cursor: pointer;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-light);
    border-radius: 8px;
    transition: border-color var(--el-transition-duration-fast);

    &:hover {
        border-color: var(--el-text-color-primary);
    }
}

.connect-drawer__form :deep(.el-form-item__label) {
    width: 100%;
    font-weight: 500;
    color: var(--el-text-color-primary);
}

.connect-drawer__hint {
    margin-top: 6px;
    font-size: 12px;
    line-height: 16px;
    color: var(--el-text-color-secondary);
}

.connect-drawer__section {
    margin-bottom: 14px;
    font-size: 11px;
    font-weight: 600;
    color: var(--el-text-color-placeholder);
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
</style>

<style lang="scss">
// el-drawer renders outside the component (teleport): the body padding is set without :deep.
.connect-drawer .el-drawer__body {
    padding: 0;
}
</style>
