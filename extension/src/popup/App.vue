<script setup>
import { computed, onMounted, ref } from 'vue';
import { adapterFor } from '../adapters/index.js';
import { APP_URL } from '../config.js';
import { MESSAGE, send } from '../core/messages.js';
import { marketplaceForUrl } from '../marketplaces.js';
import { formatAction, formatTime, sumStats } from './format.js';

// Three blocks (docs/browser-extension.md, «Интерфейс»): the account, overall counts, and the marketplace whose
// site is open in the current tab. Signed out: the Kadrobit sign-in, or a personal token as a fallback.
const loading = ref(true);
const busy = ref(false);
const error = ref(null);
const state = ref(null);
const tab = ref(null);
const tokenFormOpen = ref(false);
const token = ref('');

const current = computed(() => marketplaceForUrl(tab.value?.url));
const currentAccounts = computed(() => state.value?.me?.accounts.filter((account) => account.marketplace.code === current.value?.code) ?? []);
const currentStats = computed(() => sumStats(currentAccounts.value));
const currentAction = computed(() => current.value && state.value?.actions[current.value.code]);
const autoSubmit = computed(() => currentAccounts.value.some((account) => account.auto_submit));
const canRun = computed(() => current.value && adapterFor(current.value.code) && currentAccounts.value.length);

const STATS = [
    { key: 'sent', label: 'Uploaded' },
    { key: 'pending', label: 'In review' },
    { key: 'rejected', label: 'Rejected' },
];

async function attempt(work) {
    busy.value = true;
    error.value = null;

    try {
        await work();
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

async function refresh() {
    state.value = await send(MESSAGE.STATE);
    error.value = state.value.notice;
}

const signIn = () => attempt(async () => {
    await send(MESSAGE.SIGN_IN);
    await refresh();
});

const signInWithToken = () => attempt(async () => {
    await send(MESSAGE.SIGN_IN_WITH_TOKEN, { token: token.value });
    token.value = '';
    tokenFormOpen.value = false;
    await refresh();
});

const signOut = () => attempt(async () => {
    await send(MESSAGE.SIGN_OUT);
    await refresh();
});

const checkNow = () => attempt(async () => {
    // A tab opened before the extension was installed or updated has no content script to answer.
    const answer = await chrome.tabs.sendMessage(tab.value.id, { type: MESSAGE.RUN }).catch(() => null);

    if (!answer?.ok) {
        throw new Error(`Reload the ${current.value.name} page and try again.`);
    }

    await refresh();
});

onMounted(async () => {
    [tab.value] = await chrome.tabs.query({ active: true, currentWindow: true });
    await attempt(refresh);
    loading.value = false;
});
</script>

<template>
    <main v-loading="loading" class="popup">
        <header class="popup__header">
            <img src="/icons/icon-32.png" alt="" width="20" height="20" />
            <span class="popup__brand">Kadrobit</span>
        </header>

        <el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="popup__error" />

        <template v-if="state && !state.signedIn">
            <section class="popup__block">
                <p class="popup__text">Connect the extension to your Kadrobit account to finish publishing on stock sites.</p>
                <el-button type="primary" :loading="busy" class="popup__wide" @click="signIn">Sign in to Kadrobit</el-button>

                <el-link v-if="!tokenFormOpen" type="primary" underline="never" class="popup__link" @click="tokenFormOpen = true">
                    Use a personal token instead
                </el-link>
                <form v-else class="popup__token" @submit.prevent="signInWithToken">
                    <el-input v-model="token" type="textarea" :rows="3" placeholder="Paste the token from Settings → Extension" />
                    <el-button native-type="submit" :disabled="!token.trim()" :loading="busy">Save token</el-button>
                </form>
            </section>
        </template>

        <template v-else-if="state?.me">
            <section class="popup__block popup__account">
                <div class="popup__account-name">
                    <p class="popup__strong">{{ [state.me.user.first_name, state.me.user.last_name].filter(Boolean).join(' ') || state.me.user.email }}</p>
                    <p class="popup__muted">{{ state.me.user.email }}</p>
                </div>
                <el-button size="small" :loading="busy" @click="signOut">Sign out</el-button>
            </section>

            <section class="popup__block">
                <p class="popup__title">All marketplaces</p>
                <div class="popup__stats">
                    <div v-for="stat in STATS" :key="stat.key" class="popup__stat">
                        <span class="popup__number">{{ state.me.stats[stat.key] }}</span>
                        <span class="popup__muted">{{ stat.label }}</span>
                    </div>
                </div>
                <p class="popup__muted">Review checked {{ formatTime(state.me.stats.checked_at) }}</p>
            </section>

            <section v-if="current" class="popup__block">
                <div class="popup__row">
                    <p class="popup__title">{{ current.name }}</p>
                    <el-tag v-if="currentAccounts.length" size="small" :type="autoSubmit ? 'success' : 'info'">
                        {{ autoSubmit ? 'Auto submit on' : 'Auto submit off' }}
                    </el-tag>
                </div>

                <template v-if="currentAccounts.length">
                    <div class="popup__stats">
                        <div v-for="stat in STATS" :key="stat.key" class="popup__stat">
                            <span class="popup__number">{{ currentStats[stat.key] }}</span>
                            <span class="popup__muted">{{ stat.label }}</span>
                        </div>
                    </div>
                    <p class="popup__muted">Review checked {{ formatTime(currentStats.checked_at) }}</p>
                    <p v-if="currentAction" class="popup__text">
                        {{ formatAction(currentAction) }} <span class="popup__muted">· {{ formatTime(currentAction.at) }}</span>
                    </p>
                    <el-button v-if="canRun" :loading="busy" class="popup__wide" @click="checkNow">Check now</el-button>
                    <p v-else class="popup__muted">Working with {{ current.name }} is coming in a next version.</p>
                </template>
                <p v-else class="popup__text">
                    No {{ current.name }} account is connected.
                    <el-link :href="`${APP_URL}/settings/marketplaces`" target="_blank" type="primary" underline="never">Connect it in Kadrobit</el-link>
                </p>
            </section>
        </template>

        <!-- Signed in, but Kadrobit did not answer: the reason is in the alert above. -->
        <section v-else-if="state?.signedIn" class="popup__block">
            <el-button :loading="busy" class="popup__wide" @click="attempt(refresh)">Try again</el-button>
            <el-button text :loading="busy" class="popup__wide" @click="signOut">Sign out</el-button>
        </section>
    </main>
</template>

<style scoped lang="scss">
.popup {
    display: flex;
    flex-direction: column;
    min-height: 120px;
}

.popup__header {
    display: flex;
    gap: 8px;
    align-items: center;
    padding: 12px 14px;
    border-bottom: 1px solid var(--el-border-color-lighter);
}

.popup__brand {
    font-size: 14px;
    font-weight: 600;
}

.popup__error {
    margin: 10px 14px 0;
    width: auto;
}

.popup__block {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 12px 14px;
    border-bottom: 1px solid var(--el-border-color-lighter);

    &:last-child {
        border-bottom: 0;
    }
}

.popup__account {
    flex-direction: row;
    align-items: center;
}

.popup__account-name {
    flex: 1;
    min-width: 0;
}

.popup__row {
    display: flex;
    gap: 8px;
    align-items: center;
    justify-content: space-between;
}

.popup__title {
    margin: 0;
    font-weight: 600;
}

.popup__strong {
    margin: 0;
    overflow: hidden;
    font-weight: 600;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.popup__text {
    margin: 0;
    line-height: 18px;
}

.popup__muted {
    margin: 0;
    font-size: 12px;
    color: var(--el-text-color-secondary);
}

.popup__stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}

.popup__stat {
    display: flex;
    flex-direction: column;
    padding: 8px 10px;
    background: var(--el-fill-color-light);
    border-radius: 8px;
}

.popup__number {
    font-size: 18px;
    font-weight: 600;
    line-height: 24px;
}

.popup__wide {
    width: 100%;
}

.popup__link {
    align-self: center;
    font-size: 12px;
}

.popup__token {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
</style>
