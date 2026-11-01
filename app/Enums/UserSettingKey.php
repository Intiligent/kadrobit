<?php

namespace App\Enums;

use Illuminate\Validation\Rule;

/**
 * Registry of user settings: the key, its default, validation and whether the settings page may change it.
 * A new setting is one case here; nothing else needs to know its default.
 *
 * Keys are dotted: the API and the shared `settings` prop nest them ({"metadata": {"autofill_on_upload": true}}).
 */
enum UserSettingKey: string
{
    /** Interface language; also the language of the user's emails. Metadata generation stays in English. */
    case GeneralLocale = 'general.locale';

    /** Generate title, keywords and categories for a work once its upload is processed. */
    case MetadataAutofillOnUpload = 'metadata.autofill_on_upload';

    /** Browser notification when the works on their way have all reached review on the marketplaces. */
    case NotifyReviewSubmitted = 'notifications.browser.review_submitted';

    public function default(): mixed
    {
        return match ($this) {
            self::GeneralLocale => Locale::default()->value,
            self::MetadataAutofillOnUpload => true,
            self::NotifyReviewSubmitted => true,
        };
    }

    /**
     * @return list<mixed>
     */
    public function rules(): array
    {
        return match ($this) {
            self::GeneralLocale => ['string', Rule::enum(Locale::class)],
            self::MetadataAutofillOnUpload, self::NotifyReviewSubmitted => ['boolean'],
        };
    }

    /**
     * A value in the setting's stored type: for a switch "1", 1 and "yes" become true, anything else false.
     */
    public function cast(mixed $value): mixed
    {
        return match ($this) {
            self::GeneralLocale => (string) $value,
            self::MetadataAutofillOnUpload, self::NotifyReviewSubmitted => filter_var($value, FILTER_VALIDATE_BOOLEAN),
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
