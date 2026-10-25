<script setup>
import { computed, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import api from '@/core/api';
import { USER_SETTINGS_API_URL } from '@/config/constant';

defineOptions({ layout: [AppLayout, SettingsLayout] });

// Languages of App\Enums\Locale; each is named in itself (settings.general.locale.options).
defineProps({
    locales: { type: Array, required: true },
});

const page = usePage();
const saving = ref(false);

// The shared `settings` prop (App\Enums\UserSettingKey).
const locale = computed(() => page.props.settings.general.locale);

// Translations are loaded once per page, so the new language takes effect with a full reload.
async function setLocale(value) {
    saving.value = true;

    try {
        await api.patch(USER_SETTINGS_API_URL, { general: { locale: value } });
        window.location.reload();
    } catch {
        saving.value = false;
    }
}
</script>

<template>
    <Head :title="$t('settings.general.title')" />

    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[22px] font-semibold leading-[30px]">{{ $t('settings.general.title') }}</h1>
            <p class="mt-1 max-w-[560px] text-pretty text-[var(--el-text-color-secondary)]">{{ $t('settings.general.intro') }}</p>
        </div>

        <div class="flex items-start gap-4 rounded-[10px] border border-[var(--el-border-color-light)] p-4">
            <div class="min-w-0 flex-1">
                <p class="font-semibold">{{ $t('settings.general.locale.title') }}</p>
                <p class="mt-1 max-w-[560px] text-pretty text-[var(--el-text-color-secondary)]">{{ $t('settings.general.locale.text') }}</p>
            </div>
            <el-select
                :model-value="locale"
                :loading="saving"
                :disabled="saving"
                :aria-label="$t('settings.general.locale.title')"
                class="!w-[180px] flex-none"
                @change="setLocale"
            >
                <el-option v-for="code in locales" :key="code" :value="code" :label="$t(`settings.general.locale.options.${code}`)" />
            </el-select>
        </div>
    </div>
</template>
