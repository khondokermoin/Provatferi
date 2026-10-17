<?php

namespace App\Support;

use App\Models\LeadershipProfile;
use Illuminate\Validation\Rule;

/**
 * What an admin may write into a Leadership / Key Persons profile, and how it is stored (docs/LEADERSHIP_SYSTEM.md): the one
 * place that knows every field's rule, so the controller, the tests and the screen cannot drift apart.
 *
 * TEXT IS PLAIN TEXT. A biography or a quote is stored, shown and escaped as text — paragraph breaks and, on the site, "•"
 * bullet lines and plain http(s) links, nothing else; there is no HTML anywhere in this feature. So there is nothing to
 * "sanitise" into safety afterwards: markup is refused up front with a message, instead of being silently rewritten. What
 * IS removed without asking is what no one types on purpose and everyone is better off without: control characters, and the
 * bidirectional override/isolate characters that can make text read in a different order than it is stored (a name that
 * displays as someone else's). The zero-width joiners Bengali conjuncts need are kept.
 *
 * LINKS go through PublicLinks, the same rules as the organisation's own social links: https only, the platform's own
 * domain, no user-info, no markup. The public e-mail goes through InstitutionMail::publicContactProblem, so the internal
 * mailbox, the no-reply sender and the security mailbox can never be published.
 */
final class LeadershipProfileInput
{
    /** field => maximum length. The columns hold these (and more); the screen shows them as counters/limits. */
    public const MAX = [
        'name_bn' => 255, 'name_en' => 255, 'designation_bn' => 255, 'designation_en' => 255,
        'expertise_bn' => 160, 'expertise_en' => 160,
        'short_bio_bn' => 600, 'short_bio_en' => 600,
        'full_bio_bn' => 10000, 'full_bio_en' => 10000,
        'quote_bn' => 500, 'quote_en' => 500,
    ];

    /** One line of text. */
    private const SINGLE_LINE = ['name_bn', 'name_en', 'designation_bn', 'designation_en', 'expertise_bn', 'expertise_en'];

    /** Several lines of text (paragraphs). */
    private const MULTI_LINE = ['short_bio_bn', 'short_bio_en', 'full_bio_bn', 'full_bio_en', 'quote_bn', 'quote_en'];

    private const SOCIAL = ['facebook_url' => 'facebook', 'linkedin_url' => 'linkedin', 'youtube_url' => 'youtube'];

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $canPublish): array
    {
        $rules = [
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'name_bn' => ['required', 'string', 'max:'.self::MAX['name_bn'], self::plainText(false)],
            'name_en' => ['nullable', 'string', 'max:'.self::MAX['name_en'], self::plainText(false)],
            'designation_bn' => ['required', 'string', 'max:'.self::MAX['designation_bn'], self::plainText(false)],
            'designation_en' => ['nullable', 'string', 'max:'.self::MAX['designation_en'], self::plainText(false)],
            'expertise_bn' => ['nullable', 'string', 'max:'.self::MAX['expertise_bn'], self::plainText(false)],
            'expertise_en' => ['nullable', 'string', 'max:'.self::MAX['expertise_en'], self::plainText(false)],
            'short_bio_bn' => ['nullable', 'string', 'max:'.self::MAX['short_bio_bn'], self::plainText(true)],
            'short_bio_en' => ['nullable', 'string', 'max:'.self::MAX['short_bio_en'], self::plainText(true)],
            'full_bio_bn' => ['nullable', 'string', 'max:'.self::MAX['full_bio_bn'], self::plainText(true)],
            'full_bio_en' => ['nullable', 'string', 'max:'.self::MAX['full_bio_en'], self::plainText(true)],
            'quote_bn' => ['nullable', 'string', 'max:'.self::MAX['quote_bn'], self::plainText(true)],
            'quote_en' => ['nullable', 'string', 'max:'.self::MAX['quote_en'], self::plainText(true)],
            'associated_since_year' => ['nullable', 'integer', 'min:1900', 'max:'.AdminTime::year()],

            'photo' => ['nullable', 'file', 'max:5120'],
            'remove_photo' => ['nullable', 'boolean'],

            'public_email' => ['nullable', 'string', 'email:rfc', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }
                // The address becomes a mailto: link. A percent sign (an encoded line break), a comma, a quote or an angle
                // bracket has no place in an address anyone publishes, and is exactly what would turn that link into more.
                if (preg_match('/[%\s,;<>"\'\\\\]/', $value) === 1) {
                    $fail(__('admin.leadership.email_characters'));

                    return;
                }
                if (InstitutionMail::publicContactProblem($value) !== null) {
                    $fail(__('admin.leadership.email_forbidden'));
                }
            }],
            'public_phone' => ['nullable', 'string', 'max:40', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && PublicLinks::phone($value)['error'] !== null) {
                    $fail(__('admin.contact_settings.phone_invalid'));
                }
            }],
            'website_url' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || trim($value) === '') {
                    return;
                }
                $error = PublicLinks::website($value)['error'];
                if ($error !== null) {
                    $fail($error === 'host'
                        ? __('admin.leadership.url_host_website')
                        : self::linkMessage($error, __('admin.leadership.attr_website_url'), ''));
                }
            }],
        ];

        foreach (self::SOCIAL as $field => $platform) {
            $rules[$field] = ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($platform): void {
                if (! is_string($value) || trim($value) === '') {
                    return;
                }
                $error = PublicLinks::social($platform, $value)['error'];
                if ($error !== null) {
                    $fail(self::linkMessage($error, __('admin.contact_settings.attr_'.$platform), __('admin.contact_settings.sites_'.$platform)));
                }
            }];
        }

        // Publishing fields exist only for a role that may publish; for anyone else they are not even validated, because
        // normalize() never reads them (a crafted request cannot slip a status or a featured flag through).
        if ($canPublish) {
            $rules['status'] = ['nullable', Rule::in([LeadershipProfile::STATUS_ACTIVE, LeadershipProfile::STATUS_INACTIVE])];
            $rules['featured'] = ['nullable', 'boolean'];
            $rules['display_order'] = ['nullable', 'integer', 'min:0', 'max:100000'];
        }

        return $rules;
    }

    /** @return array<string, string> field => label in the admin's language */
    public static function attributes(): array
    {
        $attributes = [];
        foreach (['slug', 'name_bn', 'name_en', 'designation_bn', 'designation_en', 'expertise_bn', 'expertise_en',
            'short_bio_bn', 'short_bio_en', 'full_bio_bn', 'full_bio_en', 'quote_bn', 'quote_en', 'associated_since_year',
            'photo', 'public_email', 'public_phone', 'website_url', 'status', 'featured', 'display_order'] as $field) {
            $attributes[$field] = __('admin.leadership.attr_'.$field);
        }
        foreach (self::SOCIAL as $field => $platform) {
            $attributes[$field] = __('admin.contact_settings.attr_'.$platform);
        }

        return $attributes;
    }

    /**
     * The columns a validated request writes. An absent field is left out (so a partial request cannot blank the rest),
     * an empty value becomes NULL, links are stored in their normalised form, and — unless $canPublish — status, featured
     * and display_order are never part of the result at all.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function normalize(array $validated, bool $canPublish): array
    {
        $out = [];

        foreach (self::SINGLE_LINE as $field) {
            if (array_key_exists($field, $validated)) {
                $out[$field] = self::nullIfEmpty(self::clean((string) $validated[$field], false));
            }
        }
        foreach (self::MULTI_LINE as $field) {
            if (array_key_exists($field, $validated)) {
                $out[$field] = self::nullIfEmpty(self::clean((string) $validated[$field], true));
            }
        }

        if (array_key_exists('associated_since_year', $validated)) {
            $year = $validated['associated_since_year'];
            $out['associated_since_year'] = $year === null || $year === '' ? null : (int) $year;
        }
        if (array_key_exists('public_email', $validated)) {
            $out['public_email'] = self::nullIfEmpty(strtolower(trim((string) $validated['public_email'])));
        }
        if (array_key_exists('public_phone', $validated)) {
            $phone = PublicLinks::phone((string) $validated['public_phone']);
            $out['public_phone'] = $phone['error'] === null ? self::nullIfEmpty($phone['value']) : null;
        }
        foreach (self::SOCIAL as $field => $platform) {
            if (array_key_exists($field, $validated)) {
                $text = trim((string) $validated[$field]);
                $out[$field] = $text === '' ? null : PublicLinks::social($platform, $text)['url'];
            }
        }
        if (array_key_exists('website_url', $validated)) {
            $text = trim((string) $validated['website_url']);
            $out['website_url'] = $text === '' ? null : PublicLinks::website($text)['url'];
        }

        if ($canPublish) {
            if (isset($validated['status'])) {
                $out['status'] = (string) $validated['status'];
            }
            if (array_key_exists('featured', $validated)) {
                $out['featured'] = filter_var($validated['featured'], FILTER_VALIDATE_BOOLEAN);
            }
            if (isset($validated['display_order']) && $validated['display_order'] !== '') {
                $out['display_order'] = (int) $validated['display_order'];
            }
        }

        return $out;
    }

    /**
     * Removes what no one types on purpose: control characters (a line break is kept in multi-line text) and the
     * bidirectional override/isolate characters; normalises line endings; single-line text collapses runs of spaces; a
     * run of three or more line breaks becomes one blank line.
     */
    public static function clean(string $value, bool $multiline): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        $value = (string) preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}\x{FEFF}]/u', '', $value);

        if (! $multiline) {
            return trim((string) preg_replace('/\s+/u', ' ', $value));
        }

        $value = (string) preg_replace('/[ \t]+\n/', "\n", $value);
        $value = (string) preg_replace('/\n{3,}/', "\n\n", $value);

        return trim($value);
    }

    /**
     * Plain text only: no markup. "a < b" is fine; a `<` that opens a tag, a closing tag, a comment or a processing
     * instruction (`<b`, `</p`, `<!--`, `<?`) is not. Single-line fields may not contain a line break at all.
     */
    private static function plainText(bool $multiline): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($multiline): void {
            if (! is_string($value)) {
                return;
            }
            if (! $multiline && preg_match('/[\r\n]/', $value) === 1) {
                $fail(__('admin.leadership.single_line'));

                return;
            }
            if (preg_match('/<\s*[\/!?a-zA-Z]/', $value) === 1) {
                $fail(__('admin.leadership.no_markup'));
            }
        };
    }

    private static function linkMessage(string $error, string $attribute, string $sites): string
    {
        $key = match ($error) {
            'scheme' => 'url_scheme',
            'length' => 'url_length',
            'host' => 'url_host',
            'path' => 'url_path',
            default => 'url_invalid',
        };

        return __('admin.contact_settings.'.$key, ['attribute' => $attribute, 'sites' => $sites]);
    }

    private static function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
