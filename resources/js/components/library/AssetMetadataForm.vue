<script setup>
import { computed } from 'vue';
import { trans } from 'laravel-vue-i18n';
import KeywordInput from '@/components/library/KeywordInput.vue';
import notify from '@/core/notify';
import { categoryFields } from '@/utils/marketplaceRules';
import { KEYWORDS_MAX } from '@/config/constant';

const props = defineProps({
    asset: { type: Object, required: true },
    accounts: { type: Array, default: () => [] },
    categories: { type: Object, default: () => ({}) },
    // Folder tree: [{ id, name, children }]
    folders: { type: Array, default: () => [] },
    // { field: message } from the last failed save
    errors: { type: Object, default: () => ({}) },
    // Read-only, e.g. until the email is confirmed
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update']);

const set = (field, value) => emit('update', { [field]: value });

const fields = computed(() => categoryFields(props.asset, props.accounts, props.categories));

function setCategories(code, value) {
    const list = Array.isArray(value) ? value : value ? [value] : [];
    set('categories', { ...props.asset.categories, [code]: list });
}

async function copyKeywords() {
    await navigator.clipboard?.writeText(props.asset.keywords.join(', '));
    notify.success({ message: trans('library.fields.keywords_copied'), duration: 2000 });
}
</script>

<template>
    <el-form label-position="top" :disabled="disabled" class="metadata-form" @submit.prevent>
        <el-form-item :label="$t('library.folders.move_to')" :error="errors.folder_id">
            <el-tree-select
                :model-value="asset.folder_id ?? ''"
                :data="folders"
                :props="{ label: 'name', children: 'children' }"
                node-key="id"
                check-strictly
                default-expand-all
                filterable
                clearable
                :render-after-expand="false"
                :placeholder="$t('library.folders.unsorted')"
                class="w-full"
                @update:model-value="set('folder_id', $event || null)"
            />
        </el-form-item>

        <el-form-item :label="$t('library.fields.title')" :error="errors.title">
            <!-- A textarea so a long title wraps and stays readable; it is still one line: Enter (except the one that ends
                 an IME composition) and pasted breaks are dropped. -->
            <el-input
                :model-value="asset.title"
                type="textarea"
                :autosize="{ minRows: 1, maxRows: 4 }"
                maxlength="200"
                show-word-limit
                :placeholder="$t('library.fields.title_placeholder')"
                @keydown.enter="!$event.isComposing && $event.preventDefault()"
                @update:model-value="set('title', $event.replace(/\s*[\r\n]+\s*/g, ' '))"
            />
        </el-form-item>

        <el-form-item :label="$t('library.fields.description')" :error="errors.description">
            <el-input
                :model-value="asset.description"
                type="textarea"
                :autosize="{ minRows: 3, maxRows: 6 }"
                maxlength="200"
                show-word-limit
                :placeholder="$t('library.fields.description_placeholder')"
                @update:model-value="set('description', $event)"
            />
        </el-form-item>

        <el-form-item :error="errors.keywords">
            <template #label>
                <span class="flex w-full items-baseline justify-between">
                    <span>{{ $t('library.fields.keywords') }}</span>
                    <span class="text-[13px] font-normal text-[var(--el-text-color-placeholder)]">{{ asset.keywords.length }} / {{ KEYWORDS_MAX }}</span>
                </span>
            </template>
            <p class="mb-1.5 text-[13px] leading-[18px] text-[var(--el-text-color-secondary)]">{{ $t('library.fields.keywords_hint') }}</p>
            <KeywordInput
                :model-value="asset.keywords"
                :placeholder="$t('library.fields.keywords_placeholder')"
                :disabled="disabled"
                @update:model-value="set('keywords', $event)"
            />
            <div class="mt-1.5 flex gap-3">
                <el-link type="primary" underline="never" :disabled="!asset.keywords.length" @click="copyKeywords">
                    {{ $t('library.fields.copy_keywords') }}
                </el-link>
                <el-link underline="never" :disabled="disabled || !asset.keywords.length" @click="set('keywords', [])">
                    {{ $t('library.fields.clear_keywords') }}
                </el-link>
            </div>
        </el-form-item>

        <el-form-item v-if="fields.length" :label="$t('library.fields.categories')" :error="errors.categories">
            <div class="grid w-full grid-cols-[96px_minmax(0,1fr)] items-center gap-2">
                <template v-for="field in fields" :key="field.code">
                    <span class="truncate text-[var(--el-text-color-secondary)]">{{ field.name }}</span>
                    <el-select
                        v-if="field.max === 1"
                        :model-value="asset.categories[field.code]?.[0] ?? ''"
                        filterable
                        clearable
                        :placeholder="$t(field.min ? 'library.fields.category_required' : 'library.fields.category_optional')"
                        @update:model-value="setCategories(field.code, $event)"
                    >
                        <el-option v-for="option in field.options" :key="option.id" :label="option.name" :value="option.id" />
                    </el-select>
                    <el-select
                        v-else
                        :model-value="asset.categories[field.code] ?? []"
                        multiple
                        :multiple-limit="field.max"
                        filterable
                        collapse-tags
                        collapse-tags-tooltip
                        :placeholder="$t(field.min ? 'library.fields.category_required' : 'library.fields.category_optional')"
                        @update:model-value="setCategories(field.code, $event)"
                    >
                        <el-option v-for="option in field.options" :key="option.id" :label="option.name" :value="option.id" />
                    </el-select>
                </template>
            </div>
        </el-form-item>

        <el-divider class="!my-4" />

        <div class="flex flex-col gap-3">
            <label class="flex cursor-pointer items-start gap-3">
                <span class="flex-1">
                    <span class="block font-medium">{{ $t('library.fields.editorial') }}</span>
                    <span class="block text-[13px] leading-[18px] text-[var(--el-text-color-secondary)]">{{ $t('library.fields.editorial_hint') }}</span>
                </span>
                <el-switch :model-value="asset.is_editorial" @update:model-value="set('is_editorial', $event)" />
            </label>
            <label class="flex cursor-pointer items-start gap-3">
                <span class="flex-1">
                    <span class="block font-medium">{{ $t('library.fields.ai') }}</span>
                    <span class="block text-[13px] leading-[18px] text-[var(--el-text-color-secondary)]">{{ $t('library.fields.ai_hint') }}</span>
                </span>
                <el-switch :model-value="asset.is_ai_generated" @update:model-value="set('is_ai_generated', $event)" />
            </label>
        </div>
    </el-form>
</template>

<style scoped lang="scss">
.metadata-form {
    :deep(.el-form-item) {
        margin-bottom: 20px;
    }

    :deep(.el-form-item__label) {
        width: 100%;
        font-weight: 500;
        color: var(--el-text-color-primary);
    }
}
</style>
