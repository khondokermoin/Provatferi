<?php

namespace App\Support\Recruitment;

use App\Models\Setting;

/**
 * The central volunteer Messenger group (docs/VOLUNTEER_MESSENGER_GROUP.md): ONE invite link, kept in two admin-managed
 * settings and shown only on the confirmation an applicant reaches after a successful submission (see
 * ApplicationSuccessProof). It is never written into a template, a posting, a notice or an application row, and it is
 * never part of any public payload: the success endpoint hands it out after it has verified the proof, nothing else does.
 *
 * Both settings are private rows (`is_public = false`) under the `recruitment` group, so the public settings API, which
 * reads public rows only, can never return them.
 *
 * What an admin may save here is limited to a Messenger GROUP invite, and is stored in one canonical shape,
 * https://m.me/j/<token>/, whichever of the equivalent forms Messenger's "copy invite link" produced:
 *   https://www.messenger.com/j/<token>/?send_source=gc%3Acopy_invite_link_c
 *   https://m.me/j/<token>/?send_source=gc:copy_invite_link_c
 *   https://m.me/j/<token>/
 * The check is one strict pattern over the whole string, not a URL parser: a parser and a browser can disagree about
 * `https://m.me\@evil.example/`, a pattern that names every character it accepts cannot.
 */
final class CentralMessengerGroup
{
    public const GROUP = 'recruitment';

    public const KEY_ENABLED = 'recruitment.central_messenger_enabled';

    public const KEY_URL = 'recruitment.central_messenger_invite_url';

    /** The only hosts a group invite may live on (matched case-insensitively; the stored form always uses m.me). */
    public const HOSTS = ['m.me', 'messenger.com', 'www.messenger.com'];

    /** The shortest token accepted: the invites seen are 16 characters; this only rules out an obviously truncated paste. */
    private const TOKEN = '[A-Za-z0-9_-]{6,100}';

    /**
     * https only; host from the list above; the path is exactly /j/<token> with an optional closing slash; an optional
     * query and fragment are allowed to be PASTED (Messenger adds `send_source`) and are dropped. Nothing else: no
     * user-info, no port, no backslash, no whitespace, no markup characters, no second path segment.
     */
    private const PATTERN = '~^(?i:https)://(?i:m\.me|(?:www\.)?messenger\.com)/j/('.self::TOKEN.')/?(?:\?[^\s#<>"\'\\\\\x00-\x1f\x7f]*)?(?:\#[^\s<>"\'\\\\\x00-\x1f\x7f]*)?$~D';

    /**
     * The canonical invite for whatever an admin pasted, or null when it is not a Messenger group invite.
     */
    public static function normalize(?string $input): ?string
    {
        // Only ordinary whitespace around the paste is forgiven; a control character (a NUL included) is never trimmed away.
        $input = trim((string) $input, " \t\r\n");
        if ($input === '' || strlen($input) > 500 || preg_match(self::PATTERN, $input, $match) !== 1) {
            return null;
        }

        return 'https://m.me/j/'.$match[1].'/';
    }

    /** The invite token of a canonical (or pasted) invite, or null. */
    public static function tokenOf(?string $input): ?string
    {
        $canonical = self::normalize($input);

        return $canonical === null ? null : substr($canonical, strlen('https://m.me/j/'), -1);
    }

    public static function enabled(): bool
    {
        return (bool) Setting::get(self::KEY_ENABLED, false);
    }

    /** The stored invite in canonical form; a value that is not a valid invite (it can only get there by a direct write) reads as unset. */
    public static function inviteUrl(): ?string
    {
        $stored = Setting::get(self::KEY_URL);

        return is_string($stored) ? self::normalize($stored) : null;
    }

    /**
     * What the confirmation may show: the invite when the feature is switched on and a valid invite is stored, else null.
     *
     * @return array{url: string}|null
     */
    public static function publicAction(): ?array
    {
        if (! self::enabled()) {
            return null;
        }
        $url = self::inviteUrl();

        return $url === null ? null : ['url' => $url];
    }

    /**
     * True when $text carries the stored invite's token in any form (a plain link, a share link with a query, the token
     * alone). Used to keep the invite out of text an admin writes for the PUBLIC pages, whether or not the feature is
     * currently switched on: the token is the secret, not the switch.
     */
    public static function containsInvite(?string $text): bool
    {
        $token = self::tokenOf(Setting::get(self::KEY_URL));

        return $token !== null && is_string($text) && $text !== '' && str_contains($text, $token);
    }
}
