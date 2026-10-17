<?php

namespace App\Services\Mfa;

use RuntimeException;

/**
 * Recovery codes (docs/MFA_SECURITY.md, "Recovery codes"): ten single-use codes shown ONCE, when they are created.
 *
 * Each code is ten characters from a 32-letter alphabet without look-alikes (no 0/O, 1/I), written XXXXX-XXXXX: 50 random
 * bits from random_int(). Only a keyed one-way hash is stored — HMAC-SHA256 under a key derived from the application key —
 * so the table alone gives nothing away, not even to an offline guess. A code is compared with hash_equals and, once used,
 * its hash is removed.
 */
class RecoveryCodes
{
    public const COUNT = 10;

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const HALF = 5;

    /** @return list<string> */
    public function generate(): array
    {
        $codes = [];
        while (count($codes) < self::COUNT) {
            $code = $this->randomBlock().'-'.$this->randomBlock();
            $codes[$code] = true; // a duplicate (vanishingly unlikely) is simply drawn again
        }

        return array_keys($codes);
    }

    /** Upper case, no spaces or hyphens: what a person types is compared with what was shown, however it was typed. */
    public function normalize(#[\SensitiveParameter] string $input): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $input));
    }

    public function hash(#[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $this->normalize($code), $this->key());
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    public function hashAll(#[\SensitiveParameter] array $codes): array
    {
        return array_map(fn (string $code) => $this->hash($code), $codes);
    }

    /**
     * The index of the stored hash this code matches, or null. Every stored hash is compared (no early exit on a match
     * position that could be timed).
     *
     * @param  list<string>  $hashes
     */
    public function find(#[\SensitiveParameter] string $code, array $hashes): ?int
    {
        $candidate = $this->hash($code);
        $found = null;
        foreach (array_values($hashes) as $index => $hash) {
            if (is_string($hash) && hash_equals($hash, $candidate) && $found === null) {
                $found = $index;
            }
        }

        return $found;
    }

    /** True when the input could be a recovery code at all (length and alphabet), before any comparison. */
    public function looksValid(#[\SensitiveParameter] string $input): bool
    {
        $normalized = $this->normalize($input);

        return strlen($normalized) === self::HALF * 2 && strspn($normalized, self::ALPHABET) === self::HALF * 2;
    }

    private function randomBlock(): string
    {
        $block = '';
        for ($i = 0; $i < self::HALF; $i++) {
            $block .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $block;
    }

    /** A key used for nothing else, derived from the application key (rotating APP_KEY therefore retires the codes too). */
    private function key(): string
    {
        $appKey = (string) config('app.key');
        if ($appKey === '') {
            throw new RuntimeException('The application key is not set.');
        }
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        return hash_hmac('sha256', 'provatferi-mfa-recovery-codes-v1', $appKey, true);
    }
}
