<?php
declare(strict_types=1);

namespace Shugoi;

class LocaleResolver
{
    public static function resolve(?string $explicit, ?string $acceptLanguage): string
    {
        if ($explicit !== null && $explicit !== '') return $explicit;
        if ($acceptLanguage !== null && preg_match('/^fr\b|,\s*fr\b/i', $acceptLanguage)) return 'fr';
        return 'en';
    }
}
