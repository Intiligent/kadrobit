<?php

namespace App\Http\Requests\Settings;

use App\Enums\UserSettingKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

/**
 * Partial update of the user's settings, nested by key: {"metadata": {"autofill_on_upload": false}}.
 * Only settings the user may change are accepted; any other key is an error rather than silently ignored.
 */
class UpdateUserSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (UserSettingKey::editableCases() as $setting) {
            $rules[$setting->value] = ['sometimes', 'nullable', ...$setting->rules()];
        }

        return $rules;
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $known = array_column(UserSettingKey::editableCases(), 'value');

                foreach (array_keys(Arr::dot($this->all())) as $key) {
                    if (! in_array($key, $known, true)) {
                        $validator->errors()->add($key, __('settings.validation.unknown_setting'));
                    }
                }
            },
        ];
    }

    /**
     * The sent settings in their stored types; null resets a setting to its default.
     *
     * @return array<string, array{0: UserSettingKey, 1: mixed}>
     */
    public function changes(): array
    {
        $changes = [];

        foreach (UserSettingKey::editableCases() as $setting) {
            if ($this->has($setting->value)) {
                $value = $this->input($setting->value);
                $changes[$setting->value] = [$setting, $value === null ? null : $setting->cast($value)];
            }
        }

        return $changes;
    }
}
