<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { getActiveLanguage, trans } from 'laravel-vue-i18n';
import { Copy, Download } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import api from '@/core/api';
import notify from '@/core/notify';
import { EXTENSION_TOKENS_API_URL } from '@/config/constant';

defineOptions({ layout: [AppLayout, SettingsLayout] });

const props = defineProps({
    // The packed extension (public/extension/release.json): null until `npm run build` made one.
    release: { type: Object, default: null },
    extensionId: { type: String, required: true },
    connections: { type: Array, required: true },
});

const STEPS = ['download', 'unzip', 'open', 'developer', 'load', 'sign_in'];

const connections = ref([...props.connections]);
// A personal token is shown once, right after it is issued.
const issuedToken = ref(null);
const issuing = ref(false);

const formatDate = (iso) => new Intl.DateTimeFormat(getActiveLanguage(), { dateStyle: 'medium' }).format(new Date(iso));

async function issueToken() {
    issuing.value = true;

    try {
        const response = await api.post(EXTENSION_TOKENS_API_URL);
        connections.value = [response.data, ...connections.value];
        issuedToken.value = response.token;
    } finally {
        issuing.value = false;
    }
}

async function copyToken() {
    await navigator.clipboard.writeText(issuedToken.value);
    notify.success({ message: trans('settings.extension.token.copied'), duration: 3000 });
}

async function disconnect(connection) {
    const confirmed = await notify.confirm({
        title: trans('settings.extension.connections.disconnect_confirm.title'),
        message: trans('settings.extension.connections.disconnect_confirm.message'),
        confirmText: trans('settings.extension.connections.disconnect'),
        cancelText: trans('settings.extension.connections.cancel'),
        type: 'warning',
    });

    if (!confirmed) {
        return;
    }

    await api.delete(`${EXTENSION_TOKENS_API_URL}/${connection.id}`);
    connections.value = connections.value.filter((c) => c.id !== connection.id);
    notify.success({ message: trans('settings.extension.connections.disconnected'), duration: 3000 });
}
</script>

<template>
    <Head :title="$t('settings.extension.title')" />

    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[22px] font-semibold leading-[30px]">{{ $t('settings.extension.title') }}</h1>
            <p class="mt-1 max-w-[560px] text-pretty text-[var(--el-text-color-secondary)]">{{ $t('settings.extension.intro') }}</p>
        </div>

        <div class="flex items-center gap-4 rounded-[10px] border border-[var(--el-border-color-light)] p-4">
            <div class="min-w-0 flex-1">
                <p class="font-semibold">{{ $t('settings.extension.download.title') }}</p>
                <p v-if="release" class="mt-1 text-[var(--el-text-color-secondary)]">
                    {{ $t('settings.extension.download.version', { version: release.version, date: formatDate(release.built_at) }) }}
                </p>
                <p v-else class="mt-1 text-[var(--el-text-color-secondary)]">{{ $t('settings.extension.download.missing') }}</p>
            </div>
            <el-button v-if="release" type="primary" tag="a" :href="release.url" download class="flex-none">
                <Download :size="15" :stroke-width="2" class="mr-1.5" />{{ $t('settings.extension.download.button') }}
            </el-button>
        </div>

        <section class="flex flex-col gap-2.5">
            <h2 class="text-[15px] font-semibold leading-[22px]">{{ $t('settings.extension.install.title') }}</h2>
            <ol class="flex max-w-[640px] list-decimal flex-col gap-1.5 pl-5 text-pretty">
                <li v-for="step in STEPS" :key="step">{{ $t(`settings.extension.install.steps.${step}`) }}</li>
            </ol>
            <p class="max-w-[640px] text-pretty text-[var(--el-text-color-secondary)]">{{ $t('settings.extension.install.update') }}</p>
            <p class="text-xs text-[var(--el-text-color-placeholder)]">{{ $t('settings.extension.install.id', { id: extensionId }) }}</p>
        </section>

        <section class="flex flex-col gap-2.5">
            <h2 class="flex items-baseline gap-2 text-[15px] font-semibold leading-[22px]">
                {{ $t('settings.extension.connections.title') }}
                <span class="font-normal text-[var(--el-text-color-placeholder)]">{{ connections.length }}</span>
            </h2>

            <div v-if="connections.length" class="overflow-hidden rounded-[10px] border border-[var(--el-border-color-light)]">
                <div
                    v-for="connection in connections"
                    :key="connection.id"
                    class="flex items-center gap-4 border-t border-[var(--el-border-color-lighter)] px-4 py-3 first:border-t-0"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ $t(`settings.extension.connections.kind.${connection.kind}`) }}</p>
                        <p class="text-xs leading-[18px] text-[var(--el-text-color-secondary)]">
                            {{ $t('settings.extension.connections.dates', { issued: formatDate(connection.issued_at), expires: formatDate(connection.expires_at) }) }}
                        </p>
                    </div>
                    <el-button size="small" @click="disconnect(connection)">{{ $t('settings.extension.connections.disconnect') }}</el-button>
                </div>
            </div>
            <div v-else class="rounded-[10px] border border-dashed border-[var(--el-border-color)] p-5 text-center text-[var(--el-text-color-secondary)]">
                {{ $t('settings.extension.connections.empty') }}
            </div>
        </section>

        <section class="flex flex-col gap-2.5">
            <h2 class="text-[15px] font-semibold leading-[22px]">{{ $t('settings.extension.token.title') }}</h2>
            <p class="max-w-[640px] text-pretty text-[var(--el-text-color-secondary)]">{{ $t('settings.extension.token.text') }}</p>

            <div v-if="issuedToken" class="flex max-w-[640px] flex-col gap-1.5">
                <el-input :model-value="issuedToken" readonly type="textarea" :rows="3" class="font-mono" />
                <div class="flex items-center gap-3">
                    <el-button size="small" @click="copyToken">
                        <Copy :size="14" :stroke-width="2" class="mr-1.5" />{{ $t('settings.extension.token.copy') }}
                    </el-button>
                    <span class="text-xs text-[var(--el-color-warning-dark-2)]">{{ $t('settings.extension.token.once') }}</span>
                </div>
            </div>
            <div v-else>
                <el-button :loading="issuing" @click="issueToken">{{ $t('settings.extension.token.issue') }}</el-button>
            </div>
        </section>
    </div>
</template>
