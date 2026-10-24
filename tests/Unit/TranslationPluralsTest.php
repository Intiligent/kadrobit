<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;

/**
 * Pluralized strings ("{1} :count file|[2,*] :count files") work only when every variant starts
 * with its {n} or [n,m] marker; otherwise the marker is printed as text, by Laravel and laravel-vue-i18n alike.
 */
class TranslationPluralsTest extends TestCase
{
    public function test_every_plural_variant_starts_with_its_marker(): void
    {
        $files = glob(dirname(__DIR__, 2).'/lang/*/*.php');
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $strings = new RecursiveIteratorIterator(new RecursiveArrayIterator(require $file));

            foreach ($strings as $key => $value) {
                if (! is_string($value) || ! str_contains($value, '|') || ! preg_match('/\{\d+\}|\[\d+,\s*(\d+|\*)\]/', $value)) {
                    continue;
                }

                foreach (explode('|', $value) as $variant) {
                    $this->assertMatchesRegularExpression(
                        '/^\s*(\{\d+\}|\[\d+,\s*(\d+|\*)\])/',
                        $variant,
                        basename(dirname($file)).'/'.basename($file)." [{$key}]: every variant must start with {n} or [n,m].",
                    );
                }
            }
        }
    }
}
