<?php

namespace App\Models\Concerns;

use App\Enums\UserSettingKey;
use App\Models\UserSetting;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

/**
 * User settings: a row exists only for a setting changed from its default (App\Enums\UserSettingKey).
 * Reads use the loaded `settings` relation, so one query serves every setting of a request.
 */
trait HasSettings
{
    public function settings(): HasMany
    {
        return $this->hasMany(UserSetting::class);
    }

    public function setting(UserSettingKey $setting): mixed
    {
        $row = $this->loadMissing('settings')->settings->firstWhere('key', $setting->value);

        return $row ? $row->value : $setting->default();
    }

    /**
     * Saves a value; null or the default removes the row, so a later change of the default reaches the user.
     */
    public function setSetting(UserSettingKey $setting, mixed $value): void
    {
        if ($value === null || $value === $setting->default()) {
            $this->settings()->where('key', $setting->value)->delete();
        } else {
            $this->settings()->updateOrCreate(['key' => $setting->value], ['value' => $value]);
        }

        $this->unsetRelation('settings');
    }

    /**
     * Every setting the user can change, with defaults filled in, nested by key:
     * {"metadata": {"autofill_on_upload": true}}.
     *
     * @return array<string, mixed>
     */
    public function settingValues(): array
    {
        $values = [];

        foreach (UserSettingKey::editableCases() as $setting) {
            $values[$setting->value] = $this->setting($setting);
        }

        return Arr::undot($values);
    }
}
