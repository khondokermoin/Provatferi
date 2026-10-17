<?php

namespace App\Services\Mfa;

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

/**
 * Time-based one-time passwords (RFC 6238, the standard every authenticator app speaks: Google Authenticator, Microsoft
 * Authenticator, 1Password, Bitwarden, Authy ...). The arithmetic is pragmarx/google2fa — the TOTP engine Laravel's own
 * Fortify ships with: HMAC-SHA1, 6 digits, 30-second steps, constant-time comparison (hash_equals), secrets from random_int.
 * Nothing cryptographic is written here; this class only fixes the parameters and adds replay protection.
 *
 * CLOCK WINDOW: one step either side (WINDOW = 1), i.e. a code is accepted from 30 s before to 30 s after its own step —
 * enough for a phone whose clock is a little off and for typing, never minutes. A code older or newer than that is refused.
 * REPLAY: the step of the last accepted code is stored; a code of that step or an earlier one is refused even inside the
 * window, so a code that was seen (shoulder-surfed, phished, logged by a proxy) cannot be used a second time.
 */
class TotpService
{
    public const ISSUER = 'Provatferi ERP';

    public const WINDOW = 1;

    public const PERIOD = 30;

    public const DIGITS = 6;

    /** 32 base32 characters = 160 bits, the length RFC 4226 recommends for the shared secret. */
    public const SECRET_LENGTH = 32;

    public function __construct(private readonly Google2FA $engine = new Google2FA)
    {
    }

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(self::SECRET_LENGTH);
    }

    /**
     * The time step of the code when it is valid and newer than $lastUsedStep, otherwise null. Never throws: a malformed
     * secret or code is simply not valid.
     */
    public function verify(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code, ?int $lastUsedStep): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1) {
            return null;
        }

        try {
            // With an "old timestamp" google2fa returns the matching step instead of true; 0 means "nothing used yet".
            $step = $this->engine->verifyKeyNewer($secret, $code, $lastUsedStep ?? 0, self::WINDOW, $this->currentStep());
        } catch (Throwable) {
            return null;
        }

        return is_int($step) ? $step : null;
    }

    /** The current 30-second step, read from the application clock (the server's, NTP-synchronised; tests move it). */
    public function currentStep(): int
    {
        return intdiv(now()->getTimestamp(), self::PERIOD);
    }

    /** The otpauth:// URI an authenticator app reads from the QR code. Shown during enrolment only, never stored. */
    public function provisioningUri(string $accountLabel, #[\SensitiveParameter] string $secret): string
    {
        return $this->engine->getQRCodeUrl(self::ISSUER, $accountLabel, $secret);
    }

    /** The QR code as inline SVG (drawn on the server; no third-party QR service ever sees the secret). */
    public function qrSvg(#[\SensitiveParameter] string $uri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(208, 1), new SvgImageBackEnd));
        $svg = $writer->writeString($uri);

        return trim((string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg));
    }

    /** The secret in groups of four for typing into an app by hand. */
    public static function formatForDisplay(#[\SensitiveParameter] string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }
}
