<?php

namespace App\Support;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * THE ONE PLACE THE APPLICATION DECIDES WHICH INSTITUTIONAL ADDRESS GOES INTO WHICH HEADER (2026-10-14;
 * docs/EMAIL_ARCHITECTURE.md). The addresses themselves live in config/institution.php.
 *
 * Every message the application builds belongs to exactly one class, and the class sets its identity headers:
 *
 *   support()   passwords, accounts, membership, recruitment, committee and member notices: anything a person may
 *               need help with.                From no-reply@, Reply-To "Provatferi Support" <support@>
 *   security()  anything about the security of an account or of the system. Nothing is sent this way yet; it is
 *               the route the first such message must take.
 *                                              From no-reply@, Reply-To "Provatferi Security" <security@>
 *   internal()  staff notices addressed TO internalRecipient(), the internal mailbox.
 *                                              From no-reply@, no Reply-To
 *
 * From is always the global sender (config/mail.php `from`, the `noreply` role), and no class overrides it. No class
 * ever puts a private mailbox (isPrivate(): the internal mailbox, and the SMTP-authentication mailbox) in a From or a
 * Reply-To: a role misconfigured to point at one is left out, not used. The public contact (info@) is published, never
 * mailed from and never a Reply-To.
 *
 * Header safety: an address is used only when it is exactly one plain address with no control characters. Anything
 * else counts as absent, so the header is left out instead of the send failing (the 2026-09-09 outage was a null
 * Reply-To that only failed inside the transport). Subjects and display names are flattened to one line. Recipients
 * are validated where they enter (the `email` rule) and again by Symfony's Address; both refuse CR/LF
 * (tests/Feature/Mail/EmailIdentityTest). problems() lists every misconfiguration; `php artisan mail:identity` prints
 * it, and the release smoke test refuses a release that has any.
 */
final class InstitutionMail
{
    /** The roles of config/institution.php. */
    public const ROLES = ['public', 'support', 'security', 'noreply', 'internal', 'smtp_auth'];

    public static function support(MailMessage $message): MailMessage
    {
        return self::withReplyTo($message, 'support');
    }

    public static function security(MailMessage $message): MailMessage
    {
        return self::withReplyTo($message, 'security');
    }

    /**
     * A staff notice. It has no Reply-To: there is nothing to answer (the work happens in the ERP), and the internal
     * mailbox is never offered as an address to write to, so this cannot expose it even if the class were misused for
     * an outside recipient.
     */
    public static function internal(MailMessage $message): MailMessage
    {
        $message->replyTo = [];

        return self::oneLineSubject($message);
    }

    /** Where staff notices go, or null when no valid internal mailbox is configured (then none is sent). */
    public static function internalRecipient(): ?string
    {
        return self::address('internal');
    }

    /** The configured address of a role, or null when it is missing or not exactly one plain address. */
    public static function address(string $role): ?string
    {
        // Concatenated, not interpolated: deploy/config-contract.php reads a double-quoted key as a literal one.
        $value = config('institution.email.'.$role);

        return is_string($value) && self::isPlainAddress($value) ? $value : null;
    }

    /**
     * True for a mailbox that must never be shown to the public or offered as a sender or a reply address: the
     * internal mailbox, and the SMTP-authentication mailbox unless that is the automated sender itself. Compared
     * case-insensitively, like mailbox names.
     */
    public static function isPrivate(?string $address): bool
    {
        $address = strtolower(trim((string) $address));
        if ($address === '') {
            return false;
        }

        $private = [self::address('internal')];
        if (strtolower((string) self::address('smtp_auth')) !== strtolower((string) self::address('noreply'))) {
            $private[] = self::address('smtp_auth');
        }

        return in_array($address, array_map(fn (?string $a) => strtolower((string) $a), array_filter($private)), true);
    }

    /**
     * Why an address may NOT be published as a public contact (website, contact page, structured data), or null when it
     * may. A published address must be one a person can write to and that we read as a contact:
     *   private    the internal mailbox, the SMTP login, or any `admin@` (never public)
     *   automated  the no-reply sender: nobody reads it
     *   security   the security mailbox: for security reports, not general contact
     * Used by the admin Settings screen (a clear validation error) and again when the public API is built, so a bad
     * value that reached the database by any other route is still never served.
     */
    public static function publicContactProblem(?string $address): ?string
    {
        $address = strtolower(trim((string) $address));
        if ($address === '') {
            return null;
        }

        $local = (string) strstr($address, '@', true);
        if (self::isPrivate($address) || $local === 'admin') {
            return 'private';
        }
        if ($address === strtolower((string) self::address('noreply')) || in_array($local, ['no-reply', 'noreply', 'donotreply', 'do-not-reply'], true)) {
            return 'automated';
        }
        if ($address === strtolower((string) self::address('security')) || $local === 'security') {
            return 'security';
        }

        return null;
    }

    /**
     * Every way the configured identity breaks the e-mail architecture; empty when it is sound. Config only, so it
     * runs anywhere the application boots (the release smoke test calls it before a switch).
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $problems = [];

        foreach (['public', 'support', 'security', 'noreply', 'internal'] as $role) {
            $value = config('institution.email.'.$role);
            if (! is_string($value) || trim($value) === '') {
                $problems[] = "institution.email.{$role} is not set";
            } elseif (! self::isPlainAddress($value)) {
                $problems[] = "institution.email.{$role} is not exactly one plain e-mail address";
            }
        }

        if (config('mail.from.address') !== config('institution.email.noreply')) {
            $problems[] = 'mail.from.address is not institution.email.noreply';
        }
        $name = config('mail.from.name');
        if (! is_string($name) || $name === '' || self::headerText($name) !== $name) {
            $problems[] = 'mail.from.name is empty or not a single line';
        }

        foreach (['noreply' => 'the automated sender', 'support' => 'the support Reply-To', 'security' => 'the security Reply-To', 'public' => 'the public contact'] as $role => $what) {
            if (self::isPrivate(self::address($role))) {
                $problems[] = "{$what} (institution.email.{$role}) is a private mailbox (internal or SMTP authentication)";
            }
        }

        $sender = strtolower((string) self::address('noreply'));
        foreach (['support' => 'the support Reply-To', 'security' => 'the security Reply-To'] as $role => $what) {
            if ($sender !== '' && strtolower((string) self::address($role)) === $sender) {
                $problems[] = "{$what} (institution.email.{$role}) is the automated sender, a mailbox nobody answers";
            }
        }
        if ($sender !== '' && strtolower((string) self::address('public')) === $sender) {
            $problems[] = 'the automated sender is the public contact (institution.email.public), which is published, never a sender';
        }

        $mailer = config('mail.default');
        if ($mailer === 'smtp' && self::address('smtp_auth') === null) {
            $problems[] = 'the smtp mailer has no authentication mailbox (MAIL_USERNAME)';
        }
        // The log transport writes whole messages, password-reset links included, into the application log.
        $logged = in_array($mailer, ['log', 'array'], true)
            || ($mailer === 'failover' && in_array('log', (array) config('mail.mailers.failover.mailers'), true));
        if (app()->isProduction() && $logged) {
            $problems[] = "production mail goes to the '{$mailer}' mailer, which keeps whole messages (reset links included) instead of sending them";
        }

        return $problems;
    }

    /**
     * One line of header text: control characters (CR and LF included) and runs of spaces become a single space.
     * Byte-wise on purpose: those bytes never occur inside a multi-byte UTF-8 character, so Bengali text is untouched.
     */
    public static function headerText(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x20\x7F]+/', ' ', $value));
    }

    /** Exactly one plain address: no display name, no list, no surrounding space, no control character. */
    public static function isPlainAddress(string $value): bool
    {
        return $value === trim($value)
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function withReplyTo(MailMessage $message, string $role): MailMessage
    {
        $message->replyTo = [];

        $address = self::address($role);
        if ($address !== null && ! self::isPrivate($address)) {
            $name = self::headerText((string) config('institution.email_names.'.$role, ''));
            $message->replyTo($address, $name !== '' ? $name : null);
        }

        return self::oneLineSubject($message);
    }

    private static function oneLineSubject(MailMessage $message): MailMessage
    {
        if (is_string($message->subject)) {
            $message->subject = self::headerText($message->subject);
        }

        return $message;
    }
}
