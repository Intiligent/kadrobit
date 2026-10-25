<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { CircleCheck, CircleMinus, CircleX, Info, Plus, TriangleAlert } from 'lucide-vue-next';
import { checkAsset } from '@/utils/marketplaceRules';
import { SETTINGS_MARKETPLACES_URL } from '@/config/constant';

const props = defineProps({
    asset: { type: Object, required: true },
    accounts: { type: Array, default: () => [] },
});

const LEVEL = {
    ok: { icon: CircleCheck, class: 'text-[var(--el-color-success-dark-2)]' },
    warning: { icon: TriangleAlert, class: 'text-[var(--el-color-warning-dark-2)]' },
    error: { icon: CircleX, class: 'text-[var(--el-color-danger)]' },
    info: { icon: Info, class: 'text-[var(--el-text-color-secondary)]' },
    off: { icon: CircleMinus, class: 'text-[var(--el-text-color-secondary)]' },
};

const rows = computed(() => props.accounts.map((account) => ({ account, result: checkAsset(props.asset, account) })));

const initials = (name) => name.split(/\s+/).map((word) => word[0]).join('').slice(0, 2).toUpperCase();
</script>

<template>
    <div class="flex flex-col gap-3">
        <p class="text-[13px] leading-[18px] text-[var(--el-text-color-secondary)]">{{ $t('library.checks.intro') }}</p>

        <el-empty v-if="!accounts.length" :image-size="110" :description="$t('library.checks.none')">
            <!-- Opens the marketplace picker on the settings page right away. -->
            <Link :href="`${SETTINGS_MARKETPLACES_URL}?add=1`" class="inline-flex items-center gap-1 font-medium text-[var(--el-color-primary)] hover:underline">
                <Plus :size="15" :stroke-width="2" />{{ $t('library.checks.connect') }}
            </Link>
        </el-empty>

        <div v-else class="overflow-hidden rounded-lg border border-[var(--el-border-color-light)]">
            <div
                v-for="{ account, result } in rows"
                :key="account.id"
                class="flex gap-2.5 border-t border-[var(--el-border-color-lighter)] p-3 first:border-t-0"
                :class="{ 'opacity-60': result.off }"
            >
                <el-avatar shape="square" :size="28" :src="account.marketplace.logo_url || undefined" class="flex-none !text-[10px] !font-semibold">
                    {{ initials(account.marketplace.name) }}
                </el-avatar>
                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span class="font-medium">{{ account.label || account.marketplace.name }}</span>
                    <span
                        v-for="item in result.items"
                        :key="item.key"
                        class="flex items-start gap-1.5 text-[13px] leading-[18px]"
                        :class="LEVEL[item.level].class"
                    >
                        <component :is="LEVEL[item.level].icon" :size="14" :stroke-width="1.75" class="mt-0.5 flex-none" />
                        <span>{{ item.choice === null ? $t(item.key, item.params) : $tChoice(item.key, item.choice, item.params) }}</span>
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
