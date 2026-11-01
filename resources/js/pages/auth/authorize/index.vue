<script setup>
import { Head } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { OAUTH_AUTHORIZE_URL } from '@/config/constant';

defineOptions({ layout: AuthLayout });

// Passport's consent request (AppServiceProvider::authorizationPage). Both buttons are plain form posts:
// Passport answers with a redirect to the client, e.g. back into the browser extension.
defineProps({
    client: { type: Object, required: true },
    email: { type: String, required: true },
    scopes: { type: Array, required: true },
    state: { type: String, default: null },
    authToken: { type: String, required: true },
    csrfToken: { type: String, required: true },
});
</script>

<template>
    <Head :title="$t('auth.authorize.title')" />

    <h1 class="mb-1 text-2xl font-semibold">{{ $t('auth.authorize.title') }}</h1>
    <p class="mb-6 text-sm text-[#706f6c] dark:text-[#A1A09A]">{{ $t('auth.authorize.subtitle', { client: client.name, email }) }}</p>

    <ul class="mb-6 list-disc space-y-1.5 pl-5 text-sm">
        <li v-for="scope in scopes" :key="scope">{{ $t(`auth.authorize.scopes.${scope}`) }}</li>
    </ul>

    <div class="flex gap-3">
        <form method="post" :action="OAUTH_AUTHORIZE_URL" class="flex-1">
            <input type="hidden" name="_token" :value="csrfToken" />
            <input type="hidden" name="_method" value="DELETE" />
            <input type="hidden" name="state" :value="state" />
            <input type="hidden" name="client_id" :value="client.id" />
            <input type="hidden" name="auth_token" :value="authToken" />
            <el-button size="large" native-type="submit" class="w-full">{{ $t('auth.authorize.deny') }}</el-button>
        </form>

        <form method="post" :action="OAUTH_AUTHORIZE_URL" class="flex-1">
            <input type="hidden" name="_token" :value="csrfToken" />
            <input type="hidden" name="state" :value="state" />
            <input type="hidden" name="client_id" :value="client.id" />
            <input type="hidden" name="auth_token" :value="authToken" />
            <el-button type="primary" size="large" native-type="submit" class="w-full">{{ $t('auth.authorize.approve') }}</el-button>
        </form>
    </div>
</template>
