<?php

namespace App\Support;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;

/**
 * The admin Settings screen's contract with the `settings` table (2026-10-14; docs/PUBLIC_CONTACT_SETTINGS.md): which
 * form fields exist, which setting each one writes, how each is validated and how it is stored.
 *
 * WHY THE FIELD NAMES ARE NOT THE SETTING KEYS. The setting keys are dotted (`site.phone`). PHP rewrites every dot in an
 * incoming form-field NAME to an underscore before Laravel sees the request, so a field named `site.phone` arrives as
 * `site_phone`, the validator looks for `site.phone`, finds nothing, and every save of this screen was refused with four
 * "required" errors (found 2026-10-14: the table had never once been saved through the form). Form fields therefore use
 * the underscore form below, and this list is the ONLY mapping from a field to a key: a request can write exactly the
 * settings named here, nothing else it carries reaches the table.
 *
 * Kinds: text | multiline | email | phone | social (a platform's page URL) | flag (a switch) | map | https_url.
 */
final class SiteSettings
{
    /** @return array<string, array{key: string, kind: string, required?: bool, max?: int, platform?: string}> */
    public static function fields(): array
    {
        return [
            // Identity
            'site_name_bn' => ['key' => 'site.name_bn', 'kind' => 'text', 'required' => true, 'max' => 255],
            'site_name_en' => ['key' => 'site.name_en', 'kind' => 'text', 'required' => true, 'max' => 255],
            'site_short_name' => ['key' => 'site.short_name', 'kind' => 'text', 'required' => true, 'max' => 100],
            'site_acronym' => ['key' => 'site.acronym', 'kind' => 'text', 'max' => 50],
            'site_tagline' => ['key' => 'site.tagline', 'kind' => 'text', 'max' => 255],
            // Institutional contact
            'site_email' => ['key' => 'site.email', 'kind' => 'email', 'required' => true],
            'site_support_email' => ['key' => 'site.support_email', 'kind' => 'email', 'required' => true],
            'site_phone' => ['key' => 'site.phone', 'kind' => 'phone'],
            'site_address' => ['key' => 'site.address', 'kind' => 'multiline', 'max' => 1000],
            'site_address_en' => ['key' => 'site.address_en', 'kind' => 'multiline', 'max' => 1000],
            // Social media: a switch and an address per platform
            'site_facebook_enabled' => ['key' => 'site.facebook_enabled', 'kind' => 'flag'],
            'site_facebook_url' => ['key' => 'site.facebook_url', 'kind' => 'social', 'platform' => 'facebook'],
            'site_linkedin_enabled' => ['key' => 'site.linkedin_enabled', 'kind' => 'flag'],
            'site_linkedin_url' => ['key' => 'site.linkedin_url', 'kind' => 'social', 'platform' => 'linkedin'],
            'site_youtube_enabled' => ['key' => 'site.youtube_enabled', 'kind' => 'flag'],
            'site_youtube_url' => ['key' => 'site.youtube_url', 'kind' => 'social', 'platform' => 'youtube'],
            // Location
            'site_map_url' => ['key' => 'site.map_url', 'kind' => 'map'],
            'site_map_label' => ['key' => 'site.map_label', 'kind' => 'text', 'max' => 120],
            'site_map_label_en' => ['key' => 'site.map_label_en', 'kind' => 'text', 'max' => 120],
            // Search engines and related sites
            'site_seo_title' => ['key' => 'site.seo_title', 'kind' => 'text', 'max' => 255],
            'site_seo_description' => ['key' => 'site.seo_description', 'kind' => 'multiline', 'max' => 500],
            'site_website_url' => ['key' => 'site.website_url', 'kind' => 'https_url', 'max' => 255],
            'site_literature_url' => ['key' => 'site.literature_url', 'kind' => 'https_url', 'max' => 255],
        ];
    }

    /** Every setting key this screen may write. @return list<string> */
    public static function keys(): array
    {
        return array_values(array_map(fn (array $d) => $d['key'], self::fields()));
    }

    /** `settings.value_type` of a key: a switch is a boolean, everything else a string. */
    public static function valueTypeFor(string $key): string
    {
        $field = self::fieldFor($key);

        return $field !== null && self::fields()[$field]['kind'] === 'flag' ? 'boolean' : 'string';
    }

    /** The form field that writes a setting key (the underscore form), or null. */
    public static function fieldFor(string $key): ?string
    {
        foreach (self::fields() as $field => $def) {
            if ($def['key'] === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Validation of one submitted form. Redirects back with the errors when it fails (->validate()), so a bad value is
     * never half-saved and the admin keeps what they typed.
     *
     * @param  array<string, mixed>  $input
     */
    public static function validator(array $input): ValidatorContract
    {
        $rules = [];
        foreach (self::fields() as $field => $def) {
            $rules[$field] = self::rulesFor($field, $def);
        }

        $validator = Validator::make($input, $rules, [], self::attributes());

        // Cross-field: a platform that is switched on needs an address (a switch on an empty link would show nothing).
        $validator->after(function (ValidatorContract $v) use ($input): void {
            foreach (self::fields() as $field => $def) {
                if ($def['kind'] !== 'social') {
                    continue;
                }
                $switch = 'site_'.$def['platform'].'_enabled';
                $on = filter_var($input[$switch] ?? false, FILTER_VALIDATE_BOOLEAN);
                $empty = trim((string) ($input[$field] ?? '')) === '';
                if ($on && $empty && ! $v->errors()->has($field)) {
                    $v->errors()->add($field, __('admin.contact_settings.enabled_needs_url', ['attribute' => __('admin.contact_settings.attr_'.$def['platform'])]));
                }
            }
        });

        return $validator;
    }

    /**
     * What each validated field is stored as. A field that was not submitted is left out (so an old form or an API call
     * with fewer fields cannot blank the rest); an empty value is stored as NULL ("not set"); a switch as 'true'/'false';
     * a link as the normalised URL (PublicLinks), never as typed.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, string|null> setting key => value
     */
    public static function normalize(array $validated): array
    {
        $out = [];
        foreach (self::fields() as $field => $def) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }
            $raw = $validated[$field];
            $text = trim((string) $raw);

            $out[$def['key']] = match ($def['kind']) {
                'flag' => filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
                'social' => $text === '' ? null : PublicLinks::social($def['platform'], $text)['url'],
                'map' => $text === '' ? null : PublicLinks::map($text)['url'],
                'phone' => $text === '' ? null : PublicLinks::phone($text)['value'],
                'email' => $text === '' ? null : strtolower($text),
                default => $text === '' ? null : $text,
            };
        }

        return $out;
    }

    /** @param array{key: string, kind: string, required?: bool, max?: int, platform?: string} $def @return list<mixed> */
    private static function rulesFor(string $field, array $def): array
    {
        $required = ($def['required'] ?? false) ? 'required' : 'nullable';

        return match ($def['kind']) {
            'flag' => ['nullable', 'boolean'],
            'email' => [$required, 'string', 'email:rfc', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                $problem = InstitutionMail::publicContactProblem(is_string($value) ? $value : null);
                if ($problem !== null) {
                    $fail(__('admin.contact_settings.email_'.$problem, ['public' => (string) InstitutionMail::address('public')]));
                }
            }],
            'phone' => ['nullable', 'string', 'max:40', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && PublicLinks::phone($value)['error'] !== null) {
                    $fail(__('admin.contact_settings.phone_invalid'));
                }
            }],
            // No numeric `max:` on links: PublicLinks reports `length` itself, with the right message, and checks it first.
            'social' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($def): void {
                if (! is_string($value) || trim($value) === '') {
                    return;
                }
                $error = PublicLinks::social($def['platform'], $value)['error'];
                if ($error !== null) {
                    $fail(self::linkMessage($error, __('admin.contact_settings.attr_'.$def['platform']), __('admin.contact_settings.sites_'.$def['platform'])));
                }
            }],
            'map' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || trim($value) === '') {
                    return;
                }
                $error = PublicLinks::map($value)['error'];
                if ($error !== null) {
                    $fail(self::linkMessage($error, __('admin.contact_settings.attr_map_url'), __('admin.contact_settings.sites_map')));
                }
            }],
            'https_url' => ['nullable', 'string', 'url:https', 'max:'.($def['max'] ?? 255)],
            default => [$required, 'string', 'max:'.($def['max'] ?? 255), self::plainTextRule()],
        };
    }

    private static function plainTextRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && ! PublicLinks::isPlainText($value)) {
                $fail(__('admin.contact_settings.text_invalid'));
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
            'embed' => 'map_embed',
            default => 'url_invalid',
        };

        return __('admin.contact_settings.'.$key, ['attribute' => $attribute, 'sites' => $sites]);
    }

    /** The label of each field in validation messages (the admin's language). @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'site_name_bn' => __('admin.fields.name_bangla_label'),
            'site_name_en' => __('admin.fields.name_english_label'),
            'site_short_name' => __('admin.fields.short_name'),
            'site_acronym' => __('admin.fields.acronym_label'),
            'site_tagline' => __('admin.fields.tagline'),
            'site_email' => __('admin.contact_settings.attr_email'),
            'site_support_email' => __('admin.contact_settings.attr_support_email'),
            'site_phone' => __('admin.fields.phone_short'),
            'site_address' => __('admin.contact_settings.attr_address_bn'),
            'site_address_en' => __('admin.contact_settings.attr_address_en'),
            'site_facebook_url' => __('admin.contact_settings.attr_facebook'),
            'site_linkedin_url' => __('admin.contact_settings.attr_linkedin'),
            'site_youtube_url' => __('admin.contact_settings.attr_youtube'),
            'site_facebook_enabled' => __('admin.contact_settings.attr_facebook'),
            'site_linkedin_enabled' => __('admin.contact_settings.attr_linkedin'),
            'site_youtube_enabled' => __('admin.contact_settings.attr_youtube'),
            'site_map_url' => __('admin.contact_settings.attr_map_url'),
            'site_map_label' => __('admin.contact_settings.attr_map_label_bn'),
            'site_map_label_en' => __('admin.contact_settings.attr_map_label_en'),
            'site_seo_title' => __('admin.fields.default_site_title'),
            'site_seo_description' => __('admin.fields.default_meta_description'),
            'site_website_url' => __('admin.fields.website_main_url'),
            'site_literature_url' => __('admin.fields.literature_site_url'),
        ];
    }
}
