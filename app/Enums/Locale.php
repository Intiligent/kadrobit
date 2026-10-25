<?php

namespace App\Enums;

/**
 * Interface languages. Each one has a lang/{locale} directory with the same keys as lang/en.
 */
enum Locale: string
{
    case English = 'en';
    case Russian = 'ru';

    public static function default(): self
    {
        return self::English;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
