<script setup>
import { computed, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppRail from '@/components/app/AppRail.vue';
import { startRealtime, stopRealtime } from '@/core/realtime';
import { VERIFY_EMAIL_URL } from '@/config/constant';

const page = usePage();
const user = computed(() => page.props.auth.user);

// The layout stays across pages: one real-time connection for the whole session.
watch(() => user.value?.ulid, (ulid) => (ulid ? startRealtime(ulid) : stopRealtime()), { immediate: true });
</script>

<template>
    <div class="flex h-screen min-w-[1200px] flex-col bg-[var(--el-bg-color-page)] text-sm text-[var(--el-text-color-primary)]">
        <el-alert v-if="user && !user.email_verified" type="warning" :closable="false" show-icon class="app-layout__banner">
            <template #title>
                {{ $t('verification.banner.text') }}
                <Link :href="VERIFY_EMAIL_URL" class="ml-1 font-medium text-[var(--el-color-primary)] hover:underline">
                    {{ $t('verification.banner.action') }}
                </Link>
            </template>
        </el-alert>

        <div class="flex min-h-0 flex-1">
            <AppRail />
            <slot />
        </div>
    </div>
</template>

<style scoped lang="scss">
.app-layout__banner {
    flex: none;
    border-radius: 0;
}
</style>
