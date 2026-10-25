<?php

namespace App\Http\Requests\Settings;

use App\Enums\UserSettingKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

/**
 * Partial update of the user's settings, nested by key: {"metadata": {"autofill_on_upload": false}}.
 * Only settings the user may change are accepted; any other key in the body is an error rather than
 * silently ignored. The query string is not looked at.
 */
class UpdateUserSettingsRequest extends FormRequest
{
    /** @var array<string, UserSettingKey>|null */
    protected ?array $settings = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_map(fn (UserSettingKey $setting) => ['sometimes', 'nullable', ...$setting->rules()], $this->settings());
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectUnknown($validator, $this->post()),
        ];
    }

    /**
     * The sent settings; null resets a setting to its default. setSetting() brings the value to the setting's type.
     *
     * @return list<array{0: UserSettingKey, 1: mixed}>
     */
    public function changes(): array
    {
        $validated = $this->validated();
        $changes = [];

        foreach ($this->settings() as $key => $setting) {
            if (Arr::has($validated, $key)) {
                $changes[] = [$setting, Arr::get($validated, $key)];
            }
        }

        return $changes;
    }

    /**
     * Settings the user may change, by key.
     *
     * @return array<string, UserSettingKey>
     */
    protected function settings(): array
    {
        return $this->settings ??= array_column(
            array_map(fn (UserSettingKey $setting) => ['key' => $setting->value, 'setting' => $setting], UserSettingKey::editableCases()),
            'setting',
            'key',
        );
    }

    /**
     * Walks the body: a key that is not a setting, or one written with a dot instead of nesting
     * ({"metadata.autofill_on_upload": …}, which validation would read as a path and skip), is an error.
     * An empty object sets nothing and is let through.
     *
     * @param  array<array-key, mixed>  $data
     */
    protected function rejectUnknown(Validator $validator, array $data, string $prefix = ''): void
    {
        foreach ($data as $key => $value) {
            $path = $prefix.$key;

            if (str_contains((string) $key, '.')) {
                $validator->errors()->add($path, __('settings.validation.unknown_setting'));
            } elseif (isset($this->settings()[$path])) {
                continue;
            } elseif (is_array($value) && ($value === [] || ! array_is_list($value))) {
                $this->rejectUnknown($validator, $value, $path.'.');
            } else {
                $validator->errors()->add($path, __('settings.validation.unknown_setting'));
            }
        }
    }
}
