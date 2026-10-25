<?php

namespace App\Enums;

/**
 * Registry of user settings: the key, its default, validation and whether the settings page may change it.
 * A new setting is one case here; nothing else needs to know its default.
 *
 * Keys are dotted: the API and the shared `settings` prop nest them ({"metadata": {"autofill_on_upload": true}}).
 */
enum UserSettingKey: string
{
    /** Generate title, keywords and categories for a work once its upload is processed. */
    case MetadataAutofillOnUpload = 'metadata.autofill_on_upload';

    public function default(): mixed
    {
        return match ($this) {
            self::MetadataAutofillOnUpload => true,
        };
    }

    /**
     * @return list<mixed>
     */
    public function rules(): array
    {
        return match ($this) {
            self::MetadataAutofillOnUpload => ['boolean'],
        };
    }

    /**
     * A validated request value in its stored type: "1" and 1 from a form become true.
     */
    public function cast(mixed $value): mixed
    {
        return match ($this) {
            self::MetadataAutofillOnUpload => filter_var($value, FILTER_VALIDATE_BOOLEAN),
        };
    }

    /**
     * Whether the user changes it on the settings page; internal settings are written only by the app.
     */
    public function editable(): bool
    {
        return true;
    }

    /**
     * @return list<self>
     */
    public static function editableCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $setting) => $setting->editable()));
    }
}
