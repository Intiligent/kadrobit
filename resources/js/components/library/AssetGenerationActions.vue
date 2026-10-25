<script setup>
import { ref, watch } from 'vue';
import { RotateCcw, Sparkles } from 'lucide-vue-next';
import api from '@/core/api';
import { ASSETS_API_URL } from '@/config/constant';

const props = defineProps({
    asset: { type: Object, required: true },
    // 'regenerate' | 'restore' while the page runs one, otherwise null
    busy: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    // Bumped by the page after a regeneration or a restore: the history and the remaining count changed
    version: { type: Number, default: 0 },
});

const emit = defineEmits(['regenerate', 'restore']);

// The newest generation of the work (what "Restore generated" brings back) and the month's remaining generations
const latest = ref(null);
const remaining = ref(null);

async function load() {
    const id = props.asset.id;

    try {
        const body = await api.get(`${ASSETS_API_URL}/${id}/generations`, { silent: true });

        // Another work may have been opened meanwhile.
        if (props.asset.id === id) {
            latest.value = body.data[0] ?? null;
            remaining.value = body.meta.remaining;
        }
    } catch {
        latest.value = null;
        remaining.value = null;
    }
}

// Again for another work, after the page's action, and when a background generation is over.
watch(() => [props.asset.id, props.version], load, { immediate: true });
watch(() => props.asset.metadata_pending, (pending, was) => {
    if (was && !pending) {
        load();
    }
});
</script>

<template>
    <section class="mt-5 border-t border-[var(--el-border-color-lighter)] pt-4">
        <p class="font-medium">{{ $t('library.generation.title') }}</p>
        <p class="mt-0.5 text-[13px] leading-[18px] text-[var(--el-text-color-secondary)]">
            {{ remaining === null ? $t('library.generation.hint') : $tChoice('library.generation.remaining', remaining, { count: remaining }) }}
        </p>
        <div class="mt-2.5 flex flex-col items-start gap-1.5">
            <el-link
                type="primary"
                underline="never"
                :disabled="disabled || !!busy || asset.metadata_pending || remaining === 0"
                @click="emit('regenerate')"
            >
                <Sparkles :size="14" :stroke-width="2" class="mr-1.5" :class="{ 'animate-pulse': busy === 'regenerate' }" />
                {{ $t(busy === 'regenerate' ? 'library.generation.regenerating' : 'library.generation.regenerate_all') }}
            </el-link>
            <el-link
                v-if="latest"
                underline="never"
                :disabled="disabled || !!busy || asset.metadata_pending"
                @click="emit('restore', latest.id)"
            >
                <RotateCcw :size="14" :stroke-width="2" class="mr-1.5" />
                {{ $t('library.generation.restore') }}
            </el-link>
        </div>
    </section>
</template>
