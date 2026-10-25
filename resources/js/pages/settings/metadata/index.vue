<script setup>
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import api from '@/core/api';
import notify from '@/core/notify';
import { USER_SETTINGS_API_URL } from '@/config/constant';

defineOptions({ layout: [AppLayout, SettingsLayout] });

const page = usePage();
const saving = ref(false);

// The shared `settings` prop (App\Enums\UserSettingKey): replaced with the server's answer after each change.
const autofill = computed(() => page.props.settings.metadata.autofill_on_upload);

async function setAutofill(value) {
    saving.value = true;

    try {
        const response = await api.patch(USER_SETTINGS_API_URL, { metadata: { autofill_on_upload: value } });
        router.replaceProp('settings', response.data);
        notify.success({
            message: trans(value ? 'settings.metadata.toasts.autofill_on' : 'settings.metadata.toasts.autofill_off'),
            duration: 3000,
        });
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Head :title="$t('settings.metadata.title')" />

    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[22px] font-semibold leading-[30px]">{{ $t('settings.metadata.title') }}</h1>
            <p class="mt-1 max-w-[560px] text-pretty text-[var(--el-text-color-secondary)]">{{ $t('settings.metadata.intro') }}</p>
        </div>

        <div class="flex items-start gap-4 rounded-[10px] border border-[var(--el-border-color-light)] p-4">
            <div class="min-w-0 flex-1">
                <p class="font-semibold">{{ $t('settings.metadata.autofill.title') }}</p>
                <p class="mt-1 max-w-[560px] text-pretty text-[var(--el-text-color-secondary)]">{{ $t('settings.metadata.autofill.text') }}</p>
            </div>
            <el-switch
                :model-value="autofill"
                :loading="saving"
                :aria-label="$t('settings.metadata.autofill.title')"
                class="flex-none"
                @change="setAutofill"
            />
        </div>
    </div>
</template>
