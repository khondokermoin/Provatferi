<?php

namespace App\Services\Mail\Data;

use InvalidArgumentException;

/**
 * A search inside ONE folder of ONE mailbox. The words go to the provider as the value of one typed criterion (the Mail
 * API's own JSON field), never spliced into a query language: there is no syntax a person could type that widens it to
 * another folder or mailbox. `normalize()` is the one way to build one from what a person typed.
 */
final readonly class MailSearch
{
    public const IN_ANYWHERE = 'text';

    public const IN_FROM = 'from';

    public const IN_SUBJECT = 'subject';

    public const FIELDS = [self::IN_ANYWHERE, self::IN_FROM, self::IN_SUBJECT];

    public const MAX_LENGTH = 100;

    private function __construct(
        public string $field,
        public string $value,
    ) {}

    /** Null when nothing searchable is left after control characters and surplus spaces are removed. */
    public static function normalize(?string $words, ?string $field = null): ?self
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $words) ?? '';
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return null;
        }
        $field ??= self::IN_ANYWHERE;
        if (! in_array($field, self::FIELDS, true)) {
            throw new InvalidArgumentException('Unknown search field.');
        }

        return new self($field, mb_substr($value, 0, self::MAX_LENGTH));
    }
}
