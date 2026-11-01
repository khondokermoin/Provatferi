<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * The one backup artifact that contains secrets, and therefore the only one that is encrypted
 * (docs/BACKUP_AND_DISASTER_RECOVERY.md, "Configuration and secrets").
 *
 * WHAT IT HOLDS: the production `.env`, both WordPress `wp-config.php` files, the front controller, the `.htaccess`
 * files and the cron inventory — everything needed to stand the platform up again that is NOT in Git, precisely
 * because it must not be in a public repository.
 *
 * THE KEY IS THE OWNER'S, NOT THE SERVER'S. The passphrase comes from `BACKUP_ENCRYPTION_KEY` in the server's
 * environment, and the owner keeps the only other copy somewhere off-host. That matters for the scenario this exists
 * for: if the account is lost, the `.env` is lost with it, so a bundle encrypted with a key that lived only in that
 * `.env` would be undecryptable at the exact moment it is needed. When the key is absent the bundle is SKIPPED and the
 * run says so — it is never written in the clear instead.
 *
 * FORMAT: `PFBKV1` ‖ salt(16) ‖ iv(12) ‖ tag(16) ‖ AES-256-GCM ciphertext. The key is PBKDF2-SHA256 over the
 * passphrase with 210,000 iterations. GCM so that a tampered bundle fails to open rather than decrypting to rubbish.
 * Nothing here ever logs, returns or reports a file's contents.
 */
final class ConfigVault
{
    private const MAGIC = 'PFBKV1';
    private const ITERATIONS = 210000;

    public function __construct(private readonly ?string $passphrase)
    {
    }

    public function configured(): bool
    {
        return is_string($this->passphrase) && strlen(trim($this->passphrase)) >= 16;
    }

    /**
     * Writes the encrypted bundle. Returns safe metadata only: which names were included, how big each was, and the
     * hash of the ENCRYPTED file — never a byte of any file's contents.
     *
     * @param  array<string, string>  $files  name => absolute path
     * @return array{ok: bool, skipped?: string, members: array<string, array{bytes: int, sha256: string}>, absent: list<string>, bytes: int, sha256: string}
     */
    public function write(array $files, string $destination): array
    {
        if (! $this->configured()) {
            return [
                'ok' => false,
                'skipped' => 'BACKUP_ENCRYPTION_KEY is not set, so the configuration bundle was skipped rather than written unencrypted',
                'members' => [], 'absent' => [], 'bytes' => 0, 'sha256' => '',
            ];
        }
        if (! function_exists('openssl_encrypt')) {
            return ['ok' => false, 'skipped' => 'openssl is unavailable, so the configuration bundle was skipped', 'members' => [], 'absent' => [], 'bytes' => 0, 'sha256' => ''];
        }

        $bundle = [];
        $members = [];
        $absent = [];
        foreach ($files as $name => $path) {
            if (! is_file($path) || ! is_readable($path) || is_link($path)) {
                $absent[] = $name;

                continue;
            }
            $contents = (string) file_get_contents($path);
            $bundle[$name] = base64_encode($contents);
            $members[$name] = ['bytes' => strlen($contents), 'sha256' => hash('sha256', $contents)];
        }

        $plaintext = json_encode([
            'format' => self::MAGIC,
            'created_utc' => gmdate('c'),
            'files' => $bundle,
        ], JSON_UNESCAPED_SLASHES);
        if ($plaintext === false) {
            return ['ok' => false, 'skipped' => 'the configuration bundle could not be encoded', 'members' => [], 'absent' => $absent, 'bytes' => 0, 'sha256' => ''];
        }

        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key($salt), OPENSSL_RAW_DATA, $iv, $tag);
        // The plaintext held secrets; do not leave it lying in memory longer than necessary.
        $plaintext = str_repeat("\0", strlen($plaintext));
        unset($plaintext);

        if ($cipher === false) {
            return ['ok' => false, 'skipped' => 'encryption failed', 'members' => [], 'absent' => $absent, 'bytes' => 0, 'sha256' => ''];
        }

        // Created at 0600 before any secret reaches it.
        touch($destination);
        @chmod($destination, 0600);
        file_put_contents($destination, self::MAGIC.$salt.$iv.$tag.$cipher);
        @chmod($destination, 0600);

        return [
            'ok' => true,
            'members' => $members,
            'absent' => $absent,
            'bytes' => (int) (filesize($destination) ?: 0),
            'sha256' => (string) hash_file('sha256', $destination),
        ];
    }

    /**
     * Decrypts a bundle and writes its members into a directory. Used by the restore procedure and by the drill;
     * never by anything reachable over HTTP. Each member name is re-checked, so a tampered bundle cannot write outside
     * the destination.
     *
     * @return array{ok: bool, written: list<string>, error?: string}
     */
    public function open(string $vaultPath, string $destinationDir): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'written' => [], 'error' => 'BACKUP_ENCRYPTION_KEY is not set'];
        }
        $raw = @file_get_contents($vaultPath);
        if ($raw === false || strlen($raw) < strlen(self::MAGIC) + 44) {
            return ['ok' => false, 'written' => [], 'error' => 'the bundle is missing or too short'];
        }
        if (! str_starts_with($raw, self::MAGIC)) {
            return ['ok' => false, 'written' => [], 'error' => 'the bundle is not in the expected format'];
        }
        $offset = strlen(self::MAGIC);
        $salt = substr($raw, $offset, 16);
        $iv = substr($raw, $offset + 16, 12);
        $tag = substr($raw, $offset + 28, 16);
        $cipher = substr($raw, $offset + 44);

        $plaintext = openssl_decrypt($cipher, 'aes-256-gcm', $this->key($salt), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            return ['ok' => false, 'written' => [], 'error' => 'the bundle did not decrypt: wrong key, or it has been altered'];
        }
        $decoded = json_decode($plaintext, true);
        if (! is_array($decoded) || ! is_array($decoded['files'] ?? null)) {
            return ['ok' => false, 'written' => [], 'error' => 'the bundle decrypted but its contents are not valid'];
        }

        if (! is_dir($destinationDir) && ! @mkdir($destinationDir, 0700, true) && ! is_dir($destinationDir)) {
            return ['ok' => false, 'written' => [], 'error' => 'the destination directory could not be created'];
        }
        @chmod($destinationDir, 0700);

        $written = [];
        foreach ($decoded['files'] as $name => $encoded) {
            if (! is_string($name) || ! is_string($encoded)) {
                continue;
            }
            $target = BackupPaths::resolveInside($destinationDir, $name);
            if ($target === null) {
                throw new RuntimeException('the bundle names a member that would be written outside the destination');
            }
            $dir = dirname($target);
            if (! is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            touch($target);
            @chmod($target, 0600);
            file_put_contents($target, (string) base64_decode($encoded, true));
            @chmod($target, 0600);
            $written[] = $name;
        }

        return ['ok' => true, 'written' => $written];
    }

    private function key(string $salt): string
    {
        return hash_pbkdf2('sha256', (string) $this->passphrase, $salt, self::ITERATIONS, 32, true);
    }
}
