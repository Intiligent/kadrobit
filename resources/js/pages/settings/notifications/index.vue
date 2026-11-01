<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { BellOff, BellRing } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import api from '@/core/api';
import notify from '@/core/notify';
import { PUSH_SUBSCRIPTIONS_API_URL, USER_SETTINGS_API_URL } from '@/config/constant';
import { pushState, turnOff, turnOn } from '@/utils/push';

defineOptions({ layout: [AppLayout, SettingsLayout] });

const props = defineProps({
    // The server's VAPID public key; null when browser notifications are not set up (docs/notifications.md).
    vapidPublicKey: { type: String, default: null },
});

const page = usePage();

// This browser: 'unsupported', 'blocked', 'on', 'off'; null until checked.
const state = ref(null);
const busy = ref(false);

onMounted(async () => {
    state.value = await pushState();
});

const hint = computed(() => {
    if (state.value === 'unsupported') {
        return 'settings.notifications.browser.unsupported';
    }

    if (!props.vapidPublicKey) {
        return 'settings.notifications.browser.unavailable';
    }

    return state.value === 'blocked' ? 'settings.notifications.browser.blocked' : null;
});

async function setBrowser(on) {
    busy.value = true;

    try {
        state.value = on ? await turnOn(props.vapidPublicKey) : await turnOff();

        if (state.value === 'on' || state.value === 'off') {
            notify.success({ message: trans(`settings.notifications.toasts.browser_${state.value}`), duration: 3000 });
        }
    } catch (error) {
        // The API's own errors are shown by api.js; the browser's (push service unreachable) are not.
        if (!error?.response) {
            notify.error({ message: trans('settings.notifications.toasts.browser_failed') });
        }

        state.value = await pushState();
    } finally {
        busy.value = false;
    }
}

// Event switches: the shared `settings` prop (App\Enums\UserSettingKey), notifications.browser.*.
const EVENTS = ['review_submitted'];

const events = computed(() => page.props.settings.notifications.browser);
const saving = ref(null);

// The event's real notification, sent through the push service like any other: the browser shows it, not the page.
const showing = ref(null);

async function showExample(event) {
    showing.value = event;

    try {
        await api.post(`${PUSH_SUBSCRIPTIONS_API_URL}/example`, { event });
    } finally {
        showing.value = null;
    }
}

async function setEvent(event, value) {
    saving.value = event;

    try {
        const response = await api.patch(USER_SETTINGS_API_URL, { notifications: { browser: { [event]: value } } });
        router.replaceProp('settings', response.data);
        notify.success({
            message: trans(value ? 'settings.notifications.toasts.event_on' : 'settings.notifications.toasts.event_off', {
                event: trans(`settings.notifications.events.${event}.title`),
            }),
            duration: 3000,
        });
    } finally {
        saving.value = null;
    }
}
</script>

<template>
    <Head :title="$t('settings.notifications.title')" />

    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-[22px] font-semibold leading-[30px]">{{ $t('settings.notifications.title') }}</h1>
            <p class="mt-1 max-w-[560px] text-pretty text-[var(--el-text-color-secondary)]">{{ $t('settings.notifications.intro') }}</p>
        </div>

        <div class="rounded-[10px] border border-[var(--el-border-color-light)]">
            <div class="flex items-start gap-4 p-4">
                <component :is="state === 'on' ? BellRing : BellOff" :size="20" :stroke-width="1.75" class="mt-px flex-none" :class="state === 'on' ? 'text-[var(--el-color-primary)]' : 'text-[var(--el-text-color-secondary)]'" />
                <div class="min-w-0 flex-1">
                    <p class="font-semibold">{{ $t('settings.notifications.browser.title') }}</p>
                    <p class="mt-1 max-w-[560px] text-pretty text-[var(--el-text-color-secondary)]">{{ $t('settings.notifications.browser.text') }}</p>
                    <p v-if="hint" class="mt-2 max-w-[560px] text-pretty text-[var(--el-color-warning-dark-2)]">{{ $t(hint) }}</p>
                    <p v-else-if="state === 'on'" class="mt-2 font-medium text-[var(--el-color-success-dark-2)]">{{ $t('settings.notifications.browser.on') }}</p>
                </div>

                <div v-if="!hint && state" class="flex flex-none items-center gap-2">
                    <el-button v-if="state === 'on'" :loading="busy" @click="setBrowser(false)">{{ $t('settings.notifications.browser.turn_off') }}</el-button>
                    <el-button v-else type="primary" :loading="busy" @click="setBrowser(true)">{{ $t('settings.notifications.browser.turn_on') }}</el-button>
                </div>
            </div>

            <div class="border-t border-[var(--el-border-color-light)] px-4 pb-1 pt-3">
                <p class="text-xs font-semibold uppercase tracking-[0.06em] text-[var(--el-text-color-placeholder)]">{{ $t('settings.notifications.events.title') }}</p>

                <div v-for="event in EVENTS" :key="event" class="flex items-start gap-4 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ $t(`settings.notifications.events.${event}.title`) }}</p>
                        <p class="mt-1 max-w-[560px] text-pretty text-[var(--el-text-color-secondary)]">{{ $t(`settings.notifications.events.${event}.text`) }}</p>
                    </div>
                    <el-tooltip :content="$t('settings.notifications.events.example_hint')" :disabled="state === 'on'" placement="top">
                        <span class="flex-none">
                            <el-button size="small" text bg :disabled="state !== 'on'" :loading="showing === event" @click="showExample(event)">
                                {{ $t('settings.notifications.events.example') }}
                            </el-button>
                        </span>
                    </el-tooltip>
                    <el-switch
                        :model-value="events[event]"
                        :loading="saving === event"
                        :aria-label="$t(`settings.notifications.events.${event}.title`)"
                        class="flex-none"
                        @change="(value) => setEvent(event, value)"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
