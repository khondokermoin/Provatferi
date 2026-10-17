<?php

namespace App\Support;

/**
 * THE ONE PLACE an admin-supplied public link or phone number is validated and normalised (2026-10-14;
 * docs/PUBLIC_CONTACT_SETTINGS.md). Pure functions, no framework state, so every rule is unit-tested with hostile input.
 *
 * What a public link may be:
 *   - https only. Not http, not javascript:, data:, vbscript:, file:, ftp:, not a scheme-relative `//host` and not a
 *     bare `facebook.com/page`: the admin pastes the address bar, which always starts with https://.
 *   - one plain ASCII host that IS the platform's domain or a sub-domain of it. `facebook.com.evil.example`,
 *     `evilfacebook.com`, `facebook.com@evil.example` (user-info), punycode and non-ASCII look-alike hosts are all refused.
 *   - no user name or password, no port other than 443, no whitespace, control character, backslash, quote, angle
 *     bracket or brace anywhere, and no non-ASCII byte (a bidirectional-override character cannot hide in a link).
 *   - a path or query that names something: the platform's home page is not "our page".
 * The result is rebuilt from the parsed parts (lower-case host, no port, no fragment), never echoed from the input.
 *
 * Map links are Google Maps links only (the Share -> Copy link address). An embed/iframe address is refused: the site never
 * embeds admin-supplied content, it opens the link in a new tab (docs/PUBLIC_CONTACT_SETTINGS.md).
 *
 * Every method returns an error CODE, not a sentence, so the admin screen can phrase it in either language
 * (lang/*\/admin.php contact_settings.*).
 */
final class PublicLinks
{
    public const PLATFORMS = ['facebook', 'linkedin', 'youtube'];

    /** platform => registrable domains; each domain and any sub-domain of it is accepted. */
    public const SOCIAL_DOMAINS = [
        'facebook' => ['facebook.com', 'fb.com', 'fb.me'],
        'linkedin' => ['linkedin.com'],
        'youtube' => ['youtube.com', 'youtu.be'],
    ];

    public const SOCIAL_MAX_LENGTH = 500;

    public const MAP_MAX_LENGTH = 1000;

    /**
     * @return array{url: ?string, error: ?string} error: scheme | invalid | length | host | path
     */
    public static function social(string $platform, ?string $input): array
    {
        [$parts, $error] = self::parse((string) $input, self::SOCIAL_MAX_LENGTH);
        if ($parts === null) {
            return ['url' => null, 'error' => $error];
        }

        if (! self::hostIsOneOf($parts['host'], self::SOCIAL_DOMAINS[$platform] ?? [])) {
            return ['url' => null, 'error' => 'host'];
        }

        if (self::isRootOnly($parts)) {
            return ['url' => null, 'error' => 'path'];
        }

        return ['url' => self::build($parts), 'error' => null];
    }

    /**
     * A Google Maps share/location link: google.com/maps…, maps.google.com, maps.app.goo.gl/<id>, goo.gl/maps/…
     *
     * @return array{url: ?string, error: ?string} error: scheme | invalid | length | host | path | embed
     */
    public static function map(?string $input): array
    {
        // Embed code pasted whole (an <iframe>, a <script>…): say so plainly, it is the most common mistake here.
        if (preg_match('/<\s*(iframe|script|embed|object|div|a)\b/i', (string) $input) === 1) {
            return ['url' => null, 'error' => 'embed'];
        }

        [$parts, $error] = self::parse((string) $input, self::MAP_MAX_LENGTH);
        if ($parts === null) {
            return ['url' => null, 'error' => $error];
        }

        $host = $parts['host'];
        $path = $parts['path'];

        // An iframe/embed address is not a link to a place; it is the thing this field must never become.
        if (str_contains(strtolower($path), '/embed') || str_contains(strtolower($path), '/api/')) {
            return ['url' => null, 'error' => 'embed'];
        }

        $isMapsPath = $path === '/maps' || str_starts_with($path, '/maps/');
        $allowed = match (true) {
            in_array($host, ['google.com', 'www.google.com', 'google.com.bd', 'www.google.com.bd'], true) => $isMapsPath,
            $host === 'maps.google.com' => true,
            $host === 'maps.app.goo.gl' => preg_match('#^/[A-Za-z0-9]{6,40}$#', $path) === 1,
            $host === 'goo.gl' => str_starts_with($path, '/maps/'),
            default => false,
        };
        if (! $allowed) {
            return ['url' => null, 'error' => in_array($host, ['google.com', 'www.google.com', 'google.com.bd', 'www.google.com.bd', 'goo.gl', 'maps.app.goo.gl'], true) ? 'path' : 'host'];
        }

        if ($host === 'maps.google.com' && self::isRootOnly($parts)) {
            return ['url' => null, 'error' => 'path'];
        }

        return ['url' => self::build($parts), 'error' => null];
    }

    /**
     * A person's own website (Leadership profiles): any public site, over https. Same character and shape rules as every
     * other link here; what is refused on top is a host that cannot be somebody's public website — an IP address or a
     * name that only exists inside a network (`.local`, `.internal`, …). The home page alone is a fine address.
     *
     * @return array{url: ?string, error: ?string} error: scheme | invalid | length | host
     */
    public static function website(?string $input): array
    {
        [$parts, $error] = self::parse((string) $input, self::SOCIAL_MAX_LENGTH);
        if ($parts === null) {
            return ['url' => null, 'error' => $error];
        }

        $host = $parts['host'];
        $isIpAddress = preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $host) === 1;
        $isPrivateName = preg_match('/\.(local|localhost|internal|intranet|lan|home|corp|test|invalid|example)$/', $host) === 1;
        if ($isIpAddress || $isPrivateName || ! preg_match('/\.[a-z]{2,}$/', $host)) {
            return ['url' => null, 'error' => 'host'];
        }

        return ['url' => self::build($parts), 'error' => null];
    }

    /**
     * One phone number as typed (digits, +, spaces, brackets, dots, hyphens; 6-15 digits). Empty is allowed: the number
     * is optional. The `tel:` link is built from the digits only, on the site.
     *
     * @return array{value: string, error: ?string} error: format
     */
    public static function phone(?string $input): array
    {
        $value = trim((string) preg_replace('/\s+/', ' ', (string) $input));
        if ($value === '') {
            return ['value' => '', 'error' => null];
        }

        $digits = (string) preg_replace('/\D/', '', $value);
        $shapeOk = preg_match('/^\+?[0-9(][0-9 ().-]{3,28}[0-9]$/', $value) === 1;
        if (! $shapeOk || strlen($digits) < 6 || strlen($digits) > 15) {
            return ['value' => $value, 'error' => 'format'];
        }

        return ['value' => $value, 'error' => null];
    }

    /** Plain single-or-multi-line text: no angle brackets, and no control character other than a line break. */
    public static function isPlainText(?string $value): bool
    {
        $value = (string) $value;

        return preg_match('/[<>]/', $value) !== 1 && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 1;
    }

    /**
     * @return array{0: ?array{host: string, path: string, query: string}, 1: ?string}
     */
    private static function parse(string $input, int $maxLength): array
    {
        $value = trim($input);
        if ($value === '') {
            return [null, 'invalid'];
        }
        if (strlen($value) > $maxLength) {
            return [null, 'length'];
        }
        // The scheme comes first: for `javascript:…`, `data:…` or a bare `facebook.com/page` the useful message is
        // "must start with https://", whatever else the text contains.
        if (preg_match('#^https://#i', $value) !== 1) {
            return [null, 'scheme'];
        }
        // Whitespace/control, non-ASCII, backslash, quotes, angle brackets, backtick, braces, pipe, caret.
        if (preg_match('/[^\x21-\x7E]|[\\\\<>"\'`{}|^]/', $value) === 1) {
            return [null, 'invalid'];
        }

        $parts = parse_url($value);
        if ($parts === false || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return [null, 'invalid'];
        }
        if (isset($parts['port']) && $parts['port'] !== 443) {
            return [null, 'invalid'];
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        $hostShape = preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host) === 1;
        if (! $hostShape || str_contains($host, 'xn--')) {
            return [null, 'invalid'];
        }

        return [['host' => $host, 'path' => $parts['path'] ?? '', 'query' => $parts['query'] ?? ''], null];
    }

    /** @param array{host: string, path: string, query: string} $parts */
    private static function build(array $parts): string
    {
        return 'https://'.$parts['host'].$parts['path'].($parts['query'] !== '' ? '?'.$parts['query'] : '');
    }

    /** @param array{host: string, path: string, query: string} $parts */
    private static function isRootOnly(array $parts): bool
    {
        return ($parts['path'] === '' || $parts['path'] === '/') && $parts['query'] === '';
    }

    /** @param list<string> $domains */
    private static function hostIsOneOf(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }
}
