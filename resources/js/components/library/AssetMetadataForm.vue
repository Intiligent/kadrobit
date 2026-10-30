<script setup>
import { computed } from 'vue';
import { trans } from 'laravel-vue-i18n';
import KeywordInput from '@/components/library/KeywordInput.vue';
import MetadataFieldLabel from '@/components/library/MetadataFieldLabel.vue';
import TagSelect from '@/components/library/TagSelect.vue';
import notify from '@/core/notify';
import { categoryFields } from '@/utils/marketplaceRules';
import { isGenerated, overLimit } from '@/utils/generation';
import { KEYWORDS_MAX } from '@/config/constant';

const props = defineProps({
    asset: { type: Object, required: true },
    accounts: { type: Array, default: () => [] },
    categories: { type: Object, default: () => ({}) },
    // Folder tree: [{ id, name, children }]
    folders: { type: Array, default: () => [] },
    // The user's tags to choose from: [{ id, name }]
    tags: { type: Array, default: () => [] },
    // { field: message } from the last failed save
    errors: { type: Object, default: () => ({}) },
    // Read-only, e.g. until the email is confirmed
    disabled: { type: Boolean, default: false },
    // Soft lengths of the title and description from the generator: { title: 70, description: 150 }
    limits: { type: Object, default: () => ({}) },
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
    notify.message.success({ message: trans('library.fields.keywords_copied') });
}

async function copyField(field) {
    await navigator.clipboard?.writeText(props.asset[field]);
    notify.message.success({ message: trans(`library.fields.${field}_copied`) });
}
</script>

<template>
    <el-form label-position="top" :disabled="disabled" class="metadata-form" @submit.prevent>
        <el-form-item :error="errors.title">
            <template #label>
                <MetadataFieldLabel :label="$t('library.fields.title')" :generated="isGenerated(asset, 'title')">
                    <!-- prevent: a click inside the label would otherwise focus the field -->
                    <el-link type="primary" underline="never" :disabled="!asset.title" @click.prevent="copyField('title')">
                        {{ $t('library.fields.copy') }}
                    </el-link>
                </MetadataFieldLabel>
            </template>
            <!-- A textarea so a long title wraps and stays readable; it is still one line: Enter (except the one that ends
                 an IME composition) and pasted breaks are dropped. -->
            <el-input
                :model-value="asset.title"
                type="textarea"
                :autosize="{ minRows: 1, maxRows: 4 }"
                maxlength="200"
                :placeholder="$t('library.fields.title_placeholder')"
                @keydown.enter="!$event.isComposing && $event.preventDefault()"
                @update:model-value="set('title', $event.replace(/\s*[\r\n]+\s*/g, ' '))"
            />
            <!-- Soft: longer is still saved, some marketplaces take it; the Marketplaces tab has each one's limit. -->
            <span v-if="limits.title" class="metadata-form__counter" :class="{ 'is-over': overLimit(asset.title, limits.title) }">
                {{ asset.title.length }} / {{ limits.title }}
            </span>
        </el-form-item>

        <el-form-item :error="errors.description">
            <template #label>
                <MetadataFieldLabel :label="$t('library.fields.description')" :generated="isGenerated(asset, 'description')">
                    <!-- prevent: a click inside the label would otherwise focus the field -->
                    <el-link type="primary" underline="never" :disabled="!asset.description" @click.prevent="copyField('description')">
                        {{ $t('library.fields.copy') }}
                    </el-link>
                </MetadataFieldLabel>
            </template>
            <el-input
                :model-value="asset.description"
                type="textarea"
                :autosize="{ minRows: 3, maxRows: 6 }"
                maxlength="200"
                :placeholder="$t('library.fields.description_placeholder')"
                @update:model-value="set('description', $event)"
            />
            <span v-if="limits.description" class="metadata-form__counter" :class="{ 'is-over': overLimit(asset.description, limits.description) }">
                {{ asset.description.length }} / {{ limits.description }}
            </span>
        </el-form-item>

        <el-form-item :error="errors.keywords">
            <template #label>
                <MetadataFieldLabel :label="$t('library.fields.keywords')" :generated="isGenerated(asset, 'keywords')">
                    <el-link type="primary" underline="never" :disabled="!asset.keywords.length" @click.prevent="copyKeywords">
                        {{ $t('library.fields.copy') }}
                    </el-link>
                </MetadataFieldLabel>
            </template>
            <p class="mb-1.5 text-[13px] leading-[18px] text-[var(--el-text-color-secondary)]">{{ $t('library.fields.keywords_hint') }}</p>
            <KeywordInput
                :model-value="asset.keywords"
                :placeholder="$t('library.fields.keywords_placeholder')"
                :disabled="disabled"
                @update:model-value="set('keywords', $event)"
            />
            <div class="mt-1.5 flex w-full items-center gap-3">
                <el-link underline="never" :disabled="disabled || !asset.keywords.length" @click="set('keywords', [])">
                    {{ $t('library.fields.clear_keywords') }}
                </el-link>
                <span class="metadata-form__counter" :class="{ 'is-over': asset.keywords.length > KEYWORDS_MAX }">
                    {{ asset.keywords.length }} / {{ KEYWORDS_MAX }}
                </span>
            </div>
        </el-form-item>

        <el-form-item v-if="fields.length" :error="errors.categories">
            <template #label>
                <MetadataFieldLabel :label="$t('library.fields.categories')" :generated="isGenerated(asset, 'categories')" />
            </template>
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
                        :collapse-tags="field.max > 2"
                        collapse-tags-tooltip
                        :placeholder="$t(field.min ? 'library.fields.category_required' : 'library.fields.category_optional')"
                        @update:model-value="setCategories(field.code, $event)"
                    >
                        <el-option v-for="option in field.options" :key="option.id" :label="option.name" :value="option.id" />
                    </el-select>
                </template>
            </div>
        </el-form-item>

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

        <el-form-item :label="$t('library.tags.label')" :error="errors.tags">
            <TagSelect :model-value="asset.tags ?? []" :tags="tags" @update:model-value="set('tags', $event)" />
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

// Under the field, on the right, where Element Plus puts its own word limit.
.metadata-form__counter {
    margin-left: auto;
    font-size: 12px;
    line-height: 18px;
    color: var(--el-text-color-placeholder);

    &.is-over {
        color: var(--el-color-warning-dark-2);
    }
}
</style>
