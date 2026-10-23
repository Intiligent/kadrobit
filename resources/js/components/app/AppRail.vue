<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Globe, LayoutGrid, LogOut, Send, SlidersHorizontal } from 'lucide-vue-next';
import { LIBRARY_URL, LOGOUT_URL } from '@/config/constant';

const page = usePage();
const user = computed(() => page.props.auth.user);

const initials = computed(() => {
    const u = user.value;
    const fromName = [u?.first_name, u?.last_name].filter(Boolean).map((part) => part[0]).join('');

    return (fromName || u?.username?.[0] || '').toUpperCase();
});

// Sections without a page yet stay visible but disabled, so the rail keeps its final shape.
const items = [
    { key: 'library', icon: LayoutGrid, label: 'library.nav.library', href: LIBRARY_URL },
    { key: 'publishing', icon: Send, label: 'library.nav.publishing', href: null },
    { key: 'marketplaces', icon: Globe, label: 'library.nav.marketplaces', href: null },
];

const isActive = (item) => item.href && page.url.startsWith(item.href);

const logout = () => router.post(LOGOUT_URL);
</script>

<template>
    <nav class="app-rail">
        <Link :href="LIBRARY_URL" class="app-rail__brand" :aria-label="page.props.appName">
            {{ page.props.appName.charAt(0) }}
        </Link>

        <el-tooltip
            v-for="item in items"
            :key="item.key"
            :content="item.href ? $t(item.label) : $t('library.nav.soon', { section: $t(item.label) })"
            placement="right"
        >
            <Link v-if="item.href" :href="item.href" class="app-rail__item" :class="{ 'is-active': isActive(item) }">
                <component :is="item.icon" :size="20" :stroke-width="1.75" />
            </Link>
            <span v-else class="app-rail__item is-disabled">
                <component :is="item.icon" :size="20" :stroke-width="1.75" />
            </span>
        </el-tooltip>

        <div class="flex-1" />

        <el-tooltip :content="$t('library.nav.soon', { section: $t('library.nav.settings') })" placement="right">
            <span class="app-rail__item is-disabled">
                <SlidersHorizontal :size="20" :stroke-width="1.75" />
            </span>
        </el-tooltip>

        <el-dropdown placement="right-end" trigger="click">
            <el-avatar :size="36" :src="user?.avatar || undefined" class="app-rail__avatar">{{ initials }}</el-avatar>
            <template #dropdown>
                <el-dropdown-menu>
                    <el-dropdown-item disabled>{{ user?.username }}</el-dropdown-item>
                    <el-dropdown-item divided @click="logout">
                        <LogOut :size="16" :stroke-width="1.75" class="mr-2" />{{ $t('auth.logout') }}
                    </el-dropdown-item>
                </el-dropdown-menu>
            </template>
        </el-dropdown>
    </nav>
</template>

<style scoped lang="scss">
.app-rail {
    display: flex;
    flex: none;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    width: 60px;
    padding: 12px 0 14px;
    background: var(--el-bg-color);
    border-right: 1px solid var(--el-border-color-light);
}

.app-rail__brand {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    margin-bottom: 14px;
    font-size: 18px;
    font-weight: 600;
    color: var(--el-color-white);
    background: var(--el-color-primary);
    border-radius: 8px;
}

.app-rail__item {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    color: var(--el-text-color-secondary);
    border-radius: 8px;
    transition:
        background-color var(--el-transition-duration-fast),
        color var(--el-transition-duration-fast);

    &:hover {
        color: var(--el-text-color-primary);
        background: var(--el-fill-color);
    }

    &.is-active {
        color: var(--el-color-primary);
        background: var(--el-color-primary-light-9);
    }

    &.is-disabled {
        cursor: not-allowed;

        &:hover {
            color: var(--el-text-color-secondary);
            background: transparent;
        }
    }
}

.app-rail__avatar {
    margin-top: 10px;
    font-size: 13px;
    font-weight: 600;
    color: var(--el-color-white);
    cursor: pointer;
    background: var(--el-text-color-primary);
}
</style>
