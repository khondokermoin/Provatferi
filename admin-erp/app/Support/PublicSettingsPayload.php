<?php

namespace App\Support;

/**
 * Builds GET /api/v1/settings from the public `settings` rows (2026-10-14; docs/PUBLIC_CONTACT_SETTINGS.md).
 * Backward compatible: every key the first version returned is still there with the same meaning; `support_email`,
 * `address_en`, `social` and `location` are additions, so a site build that does not know them keeps working.
 *
 * The API is a SECOND line of defence, not just a mirror of the table. Whatever reached the database (an old row, a
 * direct write, a bad migration), the response only ever carries:
 *   - an e-mail address that may be published (InstitutionMail::publicContactProblem: never the internal mailbox, the
 *     no-reply sender or the security mailbox) that is also a well-formed address, else null;
 *   - a phone number in the accepted shape, else null;
 *   - a social link only when its switch is ON and its address passes PublicLinks again (so a disabled link, or one that
 *     would not pass today's rules, is simply absent), in a fixed order: facebook, linkedin, youtube;
 *   - a map link only when it passes PublicLinks::map.
 * Nothing private is ever read: the caller passes the PUBLIC rows only (`is_public = true`).
 *
 * `social` is also how the site tells "this ERP is current" from "an old ERP": it is present in every current response,
 * possibly as an empty list, which the site reads as "the admin switched everything off".
 */
final class PublicSettingsPayload
{
    /** @param array<string, mixed> $values public setting rows, key => value @return array<string, mixed> */
    public static function build(array $values): array
    {
        $get = function (string $key) use ($values): ?string {
            $v = $values[$key] ?? null;

            return is_string($v) && trim($v) !== '' ? trim($v) : null;
        };

        $email = function (string $key) use ($get): ?string {
            $address = $get($key);

            return $address !== null && filter_var($address, FILTER_VALIDATE_EMAIL) !== false && InstitutionMail::publicContactProblem($address) === null
                ? $address
                : null;
        };

        $phone = $get('site.phone');
        if ($phone !== null && PublicLinks::phone($phone)['error'] !== null) {
            $phone = null;
        }

        $social = [];
        foreach (PublicLinks::PLATFORMS as $platform) {
            // A row that predates the switches (no `_enabled` key at all) keeps Facebook on: it was already public.
            $switch = array_key_exists("site.{$platform}_enabled", $values)
                ? filter_var($values["site.{$platform}_enabled"], FILTER_VALIDATE_BOOLEAN)
                : $platform === 'facebook';
            $url = $get("site.{$platform}_url");
            if (! $switch || $url === null) {
                continue;
            }
            $checked = PublicLinks::social($platform, $url);
            if ($checked['url'] !== null) {
                $social[] = ['platform' => $platform, 'url' => $checked['url']];
            }
        }
        $facebook = collect($social)->firstWhere('platform', 'facebook')['url'] ?? null;

        $mapUrl = $get('site.map_url');
        $map = $mapUrl !== null ? PublicLinks::map($mapUrl)['url'] : null;

        return [
            'organization' => [
                'name_bn' => $get('site.name_bn'),
                'name_en' => $get('site.name_en'),
                'short_name' => $get('site.short_name'),
                'acronym' => $get('site.acronym'),
                'tagline' => $get('site.tagline'),
            ],
            'contact' => [
                'email' => $email('site.email'),
                'support_email' => $email('site.support_email'),
                'phone' => $phone,
                'address' => $get('site.address'),
                'address_en' => $get('site.address_en'),
                // Legacy alias of the Facebook entry in `social` (kept so an older site build still finds it).
                'facebook_url' => $facebook,
            ],
            'social' => $social,
            'location' => $map === null ? null : [
                'map_url' => $map,
                'label' => $get('site.map_label'),
                'label_en' => $get('site.map_label_en'),
            ],
            'seo' => [
                'title' => $get('site.seo_title'),
                'description' => $get('site.seo_description'),
                'alternate_names' => json_decode((string) ($values['site.alternate_names'] ?? ''), true) ?? [],
                'canonical_url' => $get('site.website_url'),
            ],
            'links' => [
                'website_url' => $get('site.website_url'),
                'literature_url' => $get('site.literature_url'),
            ],
        ];
    }
}
