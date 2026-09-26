<?php

namespace App\Support;

/**
 * Phase 3 — the one place the admin panel's supported UI languages are named.
 *
 * Bangla stays the default: an admin who has never touched the switcher sees
 * exactly the panel they saw before this phase. English is opt-in, per user.
 */
final class AdminLocale
{
    public const DEFAULT = 'bn';

    public const SESSION_KEY = 'admin_ui_locale';

    public const COOKIE_NAME = 'provatferi_admin_locale';

    /** One year — a language choice is not something to re-make every session. */
    public const COOKIE_MINUTES = 525600;

    /** @var array<string, string> locale => the label shown in the switcher, always in its OWN language */
    public const SUPPORTED = [
        'bn' => 'বাংলা',
        'en' => 'English',
    ];

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_keys(self::SUPPORTED);
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, self::SUPPORTED);
    }

    /** Falls back to the application default rather than throwing — a bad cookie must never 500 the panel. */
    public static function normalize(?string $locale): string
    {
        return self::isSupported($locale) ? $locale : self::DEFAULT;
    }

    public static function label(string $locale): string
    {
        return self::SUPPORTED[$locale] ?? $locale;
    }
}
