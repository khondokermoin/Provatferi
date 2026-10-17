<?php

namespace App\Support;

use App\Models\LeadershipProfile;

/**
 * What the public API says about a Leadership profile (docs/LEADERSHIP_SYSTEM.md) — an explicit list of public fields,
 * never the model's attributes: nothing is serialised by default, so a column added to the table tomorrow is private until
 * someone adds it here on purpose.
 *
 * Never in a response: the database id, created_by / updated_by, status, timestamps, the display_order number, the storage
 * paths (only the two public image URLs), or anything of any other table.
 *
 * Like PublicSettingsPayload this is a SECOND line of defence rather than a mirror of the table. Whatever reached the
 * database (a row written by hand, an old row, a bad migration), the response carries only
 *   - a social link that passes PublicLinks again, in the fixed order facebook, linkedin, youtube;
 *   - a website that passes PublicLinks::website;
 *   - an e-mail that may be published (never the internal mailbox, the no-reply sender or the security mailbox) and is
 *     well-formed; a phone number in the accepted shape.
 * The e-mail and phone are in the DETAIL only: a card on the Leadership page shows the photo, name, designation, short
 * biography and the public links, nothing more.
 */
final class PublicLeadershipPayload
{
    /** @return array<string, mixed> the fields a card on the Leadership page and the homepage needs */
    public static function summary(LeadershipProfile $profile): array
    {
        return [
            'slug' => $profile->slug,
            'name_bn' => $profile->name_bn,
            'name_en' => self::text($profile->name_en),
            'designation_bn' => $profile->designation_bn,
            'designation_en' => self::text($profile->designation_en),
            'short_bio_bn' => self::text($profile->short_bio_bn),
            'short_bio_en' => self::text($profile->short_bio_en),
            'expertise_bn' => self::text($profile->expertise_bn),
            'expertise_en' => self::text($profile->expertise_en),
            'featured' => (bool) $profile->featured,
            'photo' => self::photo($profile),
            'social' => self::social($profile),
            'website_url' => self::website($profile),
        ];
    }

    /** @return array<string, mixed> the summary plus what only the profile page shows */
    public static function detail(LeadershipProfile $profile): array
    {
        return self::summary($profile) + [
            'full_bio_bn' => self::text($profile->full_bio_bn),
            'full_bio_en' => self::text($profile->full_bio_en),
            'quote_bn' => self::text($profile->quote_bn),
            'quote_en' => self::text($profile->quote_en),
            'associated_since_year' => $profile->associated_since_year,
            'public_email' => self::email($profile->public_email),
            'public_phone' => self::phone($profile->public_phone),
        ];
    }

    /** @return array{url: string, sm_url: string, width: int, height: int}|null */
    private static function photo(LeadershipProfile $profile): ?array
    {
        $url = $profile->photoUrl();
        if ($url === null) {
            return null;
        }

        return [
            'url' => $url,
            // Rows from before the card size existed (none today) fall back to the page size.
            'sm_url' => $profile->photoSmUrl() ?? $url,
            'width' => (int) $profile->photo_width,
            'height' => (int) $profile->photo_height,
        ];
    }

    /** @return list<array{platform: string, url: string}> */
    private static function social(LeadershipProfile $profile): array
    {
        $links = [];
        foreach (PublicLinks::PLATFORMS as $platform) {
            $stored = $profile->{$platform.'_url'};
            if (! is_string($stored) || trim($stored) === '') {
                continue;
            }
            $checked = PublicLinks::social($platform, $stored);
            if ($checked['url'] !== null) {
                $links[] = ['platform' => $platform, 'url' => $checked['url']];
            }
        }

        return $links;
    }

    private static function website(LeadershipProfile $profile): ?string
    {
        $stored = $profile->website_url;

        return is_string($stored) && trim($stored) !== '' ? PublicLinks::website($stored)['url'] : null;
    }

    private static function email(?string $address): ?string
    {
        $address = is_string($address) ? trim($address) : '';

        // Also no character that could turn the site's mailto: link into more than an address (see LeadershipProfileInput).
        return $address !== ''
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false
            && preg_match('/[%\s,;<>"\'\\\\]/', $address) !== 1
            && InstitutionMail::publicContactProblem($address) === null
            ? $address
            : null;
    }

    private static function phone(?string $phone): ?string
    {
        $checked = PublicLinks::phone($phone);

        return $checked['error'] === null && $checked['value'] !== '' ? $checked['value'] : null;
    }

    private static function text(?string $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
