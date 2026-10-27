<script setup>
import { computed } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { RotateCcw, RotateCw, Sparkles } from 'lucide-vue-next';

const props = defineProps({
    asset: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
    // Degrees of the rotation the page is running, otherwise null
    rotating: { type: Number, default: null },
});

const emit = defineEmits(['rotate']);

// The same checks as App\Services\Assets\AssetRotator::refusal(): the reason is shown instead of the hint.
const ROTATABLE_TYPES = ['photo', 'illustration'];
const ROTATABLE_STATUSES = ['draft', 'ready'];

const refusal = computed(() => {
    const a = props.asset;

    if (!ROTATABLE_TYPES.includes(a.type)) {
        return trans('library.tools.rotate_unsupported');
    }

    if (!ROTATABLE_STATUSES.includes(a.status)) {
        return trans('library.tools.rotate_not_editable');
    }

    if (a.listings.some((listing) => listing.status === 'queued' || listing.status === 'sending')) {
        return trans('library.tools.rotate_publishing');
    }

    return null;
});

const canRotate = computed(() => !props.disabled && !refusal.value && props.rotating === null);
</script>

<template>
    <section>
        <p class="font-medium">{{ $t('library.tools.rotate') }}</p>
        <p class="mt-0.5 text-pretty text-[13px] leading-[18px] text-[var(--el-text-color-secondary)]">
            {{ refusal ?? $t('library.tools.rotate_hint') }}
        </p>
        <div class="mt-2.5 flex gap-2">
            <el-button :disabled="!canRotate" :loading="rotating === 270" @click="emit('rotate', 270)">
                <RotateCcw v-if="rotating !== 270" :size="16" :stroke-width="1.75" class="mr-1.5" />
                {{ $t('library.tools.rotate_left') }}
            </el-button>
            <el-button :disabled="!canRotate" :loading="rotating === 90" @click="emit('rotate', 90)">
                <RotateCw v-if="rotating !== 90" :size="16" :stroke-width="1.75" class="mr-1.5" />
                {{ $t('library.tools.rotate_right') }}
            </el-button>
        </div>
    </section>

    <!-- Server-side AI upscaling (KB-57) -->
    <section class="mt-5 border-t border-[var(--el-border-color-lighter)] pt-4">
        <p class="flex items-center gap-2 font-medium">
            <Sparkles :size="16" :stroke-width="1.75" class="text-[var(--el-text-color-secondary)]" />
            {{ $t('library.tools.upscale') }}
            <el-tag size="small" type="info" round disable-transitions>{{ $t('library.tools.soon') }}</el-tag>
        </p>
        <p class="mt-0.5 text-pretty text-[13px] leading-[18px] text-[var(--el-text-color-secondary)]">{{ $t('library.tools.upscale_hint') }}</p>
    </section>
</template>
