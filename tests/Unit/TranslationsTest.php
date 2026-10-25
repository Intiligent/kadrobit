<?php

namespace Tests\Unit;

use App\Enums\Locale;
use Illuminate\Support\Arr;
use PHPUnit\Framework\TestCase;

/**
 * Every language in lang/ has the same files, keys and :placeholders as lang/en, so a string cannot be added
 * to one language only. Plural strings must be readable by Laravel and laravel-vue-i18n alike.
 */
class TranslationsTest extends TestCase
{
    /**
     * Plural forms a locale picks by number when a string has no {n} or [n,m] markers (Laravel's MessageSelector).
     */
    protected const PLURAL_FORMS = ['ru' => 3];

    protected const MARKER = '/^\s*(\{\d+\}|\[\d+,\s*(\d+|\*)\])/';

    protected const EXACT_MARKER = '/^\s*\{\d+\}/';

    public function test_every_locale_has_a_lang_directory(): void
    {
        foreach (Locale::values() as $locale) {
            $this->assertDirectoryExists($this->langPath($locale));
        }
    }

    public function test_every_locale_has_the_files_of_english(): void
    {
        $english = $this->files('en');
        $this->assertNotEmpty($english);

        foreach ($this->otherLocales() as $locale) {
            $this->assertSame($english, $this->files($locale), "lang/{$locale} must have the same files as lang/en.");
        }
    }

    public function test_every_locale_has_the_keys_of_english(): void
    {
        foreach ($this->otherLocales() as $locale) {
            foreach ($this->files('en') as $file) {
                $english = array_keys($this->strings('en', $file));
                $translated = array_keys($this->strings($locale, $file));

                $this->assertSame([], array_values(array_diff($english, $translated)), "lang/{$locale}/{$file} misses keys of lang/en.");
                $this->assertSame([], array_values(array_diff($translated, $english)), "lang/{$locale}/{$file} has keys lang/en does not.");
            }
        }
    }

    /**
     * lang/{locale}.json: strings looked up by their English text, such as the titles of the framework's error pages.
     */
    public function test_every_locale_has_the_json_strings_of_english(): void
    {
        $english = $this->json('en');
        $this->assertNotEmpty($english);

        foreach ($this->otherLocales() as $locale) {
            $this->assertEqualsCanonicalizing(array_keys($english), array_keys($this->json($locale)), "lang/{$locale}.json must have the keys of lang/en.json.");
        }
    }

    public function test_every_locale_is_named_in_the_language_picker(): void
    {
        foreach (Locale::values() as $language) {
            foreach (Locale::values() as $locale) {
                $this->assertArrayHasKey("general.locale.options.{$language}", $this->strings($locale, 'settings.php'), "lang/{$locale}/settings.php names no language [{$language}].");
            }
        }
    }

    public function test_every_translation_has_the_placeholders_of_english(): void
    {
        foreach ($this->otherLocales() as $locale) {
            foreach ($this->files('en') as $file) {
                $translated = $this->strings($locale, $file);

                foreach ($this->strings('en', $file) as $key => $value) {
                    $this->assertSame(
                        $this->placeholders($value),
                        $this->placeholders($translated[$key] ?? ''),
                        "lang/{$locale}/{$file} [{$key}]: the placeholders differ from lang/en.",
                    );
                }
            }
        }
    }

    /**
     * "{1} :count file|[2,*] :count files": a variant without its marker would print the marker as text.
     */
    public function test_a_plural_with_ranges_has_a_marker_on_every_variant(): void
    {
        foreach (Locale::values() as $locale) {
            if (isset(self::PLURAL_FORMS[$locale])) {
                continue;
            }

            foreach ($this->plurals($locale) as $label => $variants) {
                if (! preg_grep(self::MARKER, $variants)) {
                    continue;
                }

                foreach ($variants as $variant) {
                    $this->assertMatchesRegularExpression(self::MARKER, $variant, "{$label}: every variant must start with {n} or [n,m].");
                }
            }
        }
    }

    /**
     * Russian picks one|few|many by number (1, 21 файл; 2, 22 файла; 5, 11 файлов), which ranges cannot express.
     * Exact numbers may follow the forms: "… работа|… работы|… работ|{0} Нет работ". They must come last, since
     * a form is picked by its position among all variants.
     */
    public function test_a_plural_has_the_forms_of_its_locale(): void
    {
        foreach (self::PLURAL_FORMS as $locale => $forms) {
            foreach ($this->plurals($locale) as $label => $variants) {
                $this->assertGreaterThanOrEqual($forms, count($variants), "{$label}: needs {$forms} plural forms.");

                foreach (array_slice($variants, 0, $forms) as $variant) {
                    $this->assertDoesNotMatchRegularExpression(self::MARKER, $variant, "{$label}: the first {$forms} variants are the plural forms, without markers.");
                }

                foreach (array_slice($variants, $forms) as $variant) {
                    $this->assertMatchesRegularExpression(self::EXACT_MARKER, $variant, "{$label}: variants after the {$forms} plural forms must be exact numbers, like {0}.");
                }
            }
        }
    }

    /**
     * A translation pluralized where English is not would print its "|" and markers as text: the app looks
     * the string up with __(), not trans_choice().
     */
    public function test_only_strings_plural_in_english_have_variants(): void
    {
        foreach ($this->otherLocales() as $locale) {
            foreach ($this->files('en') as $file) {
                $english = $this->strings('en', $file);

                foreach ($this->strings($locale, $file) as $key => $value) {
                    if (str_contains($value, '|')) {
                        $this->assertStringContainsString('|', $english[$key] ?? '', "lang/{$locale}/{$file} [{$key}]: plural forms, but the English string is not plural.");
                    }
                }
            }
        }
    }

    /**
     * Strings the app pluralizes: those with "|" in English.
     *
     * @return array<string, list<string>>
     */
    protected function plurals(string $locale): array
    {
        $plurals = [];

        foreach ($this->files('en') as $file) {
            $translated = $this->strings($locale, $file);

            foreach ($this->strings('en', $file) as $key => $value) {
                if (str_contains($value, '|') && isset($translated[$key])) {
                    $plurals["lang/{$locale}/{$file} [{$key}]"] = explode('|', $translated[$key]);
                }
            }
        }

        return $plurals;
    }

    /**
     * @return list<string>
     */
    protected function placeholders(string $value): array
    {
        preg_match_all('/:([a-z][a-z_]*)/i', $value, $matches);

        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    protected function otherLocales(): array
    {
        return array_values(array_diff(Locale::values(), ['en']));
    }

    /**
     * @return list<string>
     */
    protected function files(string $locale): array
    {
        return array_map('basename', glob($this->langPath($locale).'/*.php') ?: []);
    }

    /**
     * Strings of a file by dotted key.
     *
     * @return array<string, string>
     */
    protected function strings(string $locale, string $file): array
    {
        $path = $this->langPath($locale).'/'.$file;

        return is_file($path) ? array_filter(Arr::dot(require $path), 'is_string') : [];
    }

    /**
     * @return array<string, string>
     */
    protected function json(string $locale): array
    {
        $path = dirname(__DIR__, 2)."/lang/{$locale}.json";

        return is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
    }

    protected function langPath(string $locale): string
    {
        return dirname(__DIR__, 2).'/lang/'.$locale;
    }
}
