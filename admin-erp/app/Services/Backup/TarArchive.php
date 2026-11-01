<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * A small ustar reader and writer, so the file backup needs no external program at all
 * (docs/BACKUP_AND_DISASTER_RECOVERY.md).
 *
 * WHY NOT `tar czf`. Shelling out would mean building a command line from paths that come from the filesystem, which
 * is the one place a backup must not be careless, and it would make the tests depend on which tar the machine has.
 * Everything here is streamed through PHP's gzip filter, so memory stays flat whatever the archive's size.
 *
 * ustar, not GNU: a name longer than 100 bytes is split across the `prefix` field (155 bytes), which every tar can
 * read. A name that still will not fit is refused loudly rather than silently truncated — a truncated name in a backup
 * is a file you cannot restore.
 */
final class TarArchive
{
    private const BLOCK = 512;

    /** @var resource|null */
    private $handle = null;

    private function __construct(private readonly string $path)
    {
    }

    public static function create(string $path): self
    {
        $archive = new self($path);
        $handle = gzopen($path, 'wb6');
        if ($handle === false) {
            throw new RuntimeException('cannot open the archive for writing');
        }
        $archive->handle = $handle;

        return $archive;
    }

    /** Adds one real file under the given relative name. Returns the bytes written for the entry's data. */
    public function addFile(string $name, string $source): int
    {
        $safe = BackupPaths::safeMemberName($name);
        if ($safe === null) {
            throw new RuntimeException('refusing to archive an unsafe member name');
        }
        $size = filesize($source);
        if ($size === false) {
            throw new RuntimeException('cannot size a file to archive');
        }
        $in = fopen($source, 'rb');
        if ($in === false) {
            throw new RuntimeException('cannot read a file to archive');
        }

        $this->write($this->header($safe, $size, (int) (filemtime($source) ?: time())));
        $written = 0;
        while (! feof($in)) {
            $chunk = fread($in, 262144);
            if ($chunk === false) {
                fclose($in);
                throw new RuntimeException('read failed while archiving');
            }
            if ($chunk !== '') {
                $this->write($chunk);
                $written += strlen($chunk);
            }
        }
        fclose($in);

        if ($written !== $size) {
            // The file changed under us. Better to fail the set than to ship an archive whose entry is the wrong size.
            throw new RuntimeException('a file changed size while it was being archived');
        }
        $padding = (self::BLOCK - ($size % self::BLOCK)) % self::BLOCK;
        if ($padding > 0) {
            $this->write(str_repeat("\0", $padding));
        }

        return $written;
    }

    /** Adds a file from a string, for small generated members such as an index. */
    public function addString(string $name, string $contents): void
    {
        $safe = BackupPaths::safeMemberName($name);
        if ($safe === null) {
            throw new RuntimeException('refusing to archive an unsafe member name');
        }
        $this->write($this->header($safe, strlen($contents), time()));
        $this->write($contents);
        $padding = (self::BLOCK - (strlen($contents) % self::BLOCK)) % self::BLOCK;
        if ($padding > 0) {
            $this->write(str_repeat("\0", $padding));
        }
    }

    /** Two zero blocks, then close. An archive without them is truncated and verification will say so. */
    public function finish(): void
    {
        $this->write(str_repeat("\0", self::BLOCK * 2));
        if ($this->handle !== null) {
            gzclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * Walks a .tar.gz without extracting it and returns what it contains. Throws when the gzip stream is unreadable,
     * a header checksum is wrong, or the archive ends without its terminator — which is exactly what a truncated or
     * corrupted backup looks like.
     *
     * @return array{entries: int, bytes: int, names: list<string>}
     */
    public static function inspect(string $path, int $maxNames = 0): array
    {
        $handle = gzopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('cannot open the archive');
        }

        $entries = 0;
        $bytes = 0;
        $names = [];
        $terminated = false;

        while (true) {
            $header = self::readExactly($handle, self::BLOCK);
            if ($header === null) {
                break;  // ended without a terminator
            }
            if (trim($header, "\0") === '') {
                // First zero block: a well-formed archive has a second one and nothing after it.
                $second = self::readExactly($handle, self::BLOCK);
                $terminated = $second !== null && trim($second, "\0") === '';
                break;
            }
            if (! self::checksumMatches($header)) {
                gzclose($handle);
                throw new RuntimeException('a header checksum does not match: the archive is corrupt');
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            $full = $prefix !== '' ? $prefix.'/'.$name : $name;
            $size = (int) octdec(trim(substr($header, 124, 12), "\0 ") ?: '0');

            $entries++;
            $bytes += $size;
            if ($maxNames > 0 && count($names) < $maxNames) {
                $names[] = $full;
            }

            $skip = $size + ((self::BLOCK - ($size % self::BLOCK)) % self::BLOCK);
            if ($skip > 0 && self::readExactly($handle, $skip) === null) {
                gzclose($handle);
                throw new RuntimeException('the archive ends inside an entry: it is truncated');
            }
        }
        gzclose($handle);

        if (! $terminated) {
            throw new RuntimeException('the archive has no end-of-archive marker: it is truncated');
        }

        return ['entries' => $entries, 'bytes' => $bytes, 'names' => $names];
    }

    /**
     * Extracts ONE member to a destination file. Used by the single-file restore path, which is the common case in an
     * incident. The member name is matched exactly; the destination is checked by the caller.
     */
    public static function extractOne(string $path, string $member, string $destination): bool
    {
        $wanted = BackupPaths::safeMemberName($member);
        if ($wanted === null) {
            throw new RuntimeException('refusing to extract an unsafe member name');
        }
        $handle = gzopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('cannot open the archive');
        }

        while (true) {
            $header = self::readExactly($handle, self::BLOCK);
            if ($header === null || trim($header, "\0") === '') {
                break;
            }
            if (! self::checksumMatches($header)) {
                gzclose($handle);
                throw new RuntimeException('a header checksum does not match: the archive is corrupt');
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            $full = $prefix !== '' ? $prefix.'/'.$name : $name;
            $size = (int) octdec(trim(substr($header, 124, 12), "\0 ") ?: '0');
            $padded = $size + ((self::BLOCK - ($size % self::BLOCK)) % self::BLOCK);

            if ($full !== $wanted) {
                if ($padded > 0 && self::readExactly($handle, $padded) === null) {
                    break;
                }

                continue;
            }

            $out = fopen($destination, 'wb');
            if ($out === false) {
                gzclose($handle);
                throw new RuntimeException('cannot open the destination for writing');
            }
            $remaining = $size;
            while ($remaining > 0) {
                $chunk = gzread($handle, (int) min(262144, $remaining));
                if ($chunk === false || $chunk === '') {
                    fclose($out);
                    gzclose($handle);
                    throw new RuntimeException('the archive ends inside the wanted entry');
                }
                fwrite($out, $chunk);
                $remaining -= strlen($chunk);
            }
            fclose($out);
            gzclose($handle);

            return true;
        }

        gzclose($handle);

        return false;
    }

    private function header(string $name, int $size, int $mtime): string
    {
        $prefix = '';
        if (strlen($name) > 100) {
            $cut = strrpos(substr($name, 0, 101), '/');
            if ($cut === false || strlen($name) - $cut - 1 > 100 || $cut > 155) {
                throw new RuntimeException('a member name is too long for the ustar format');
            }
            $prefix = substr($name, 0, $cut);
            $name = substr($name, $cut + 1);
        }

        $header = str_pad($name, 100, "\0")
            .sprintf('%07o', 0644)."\0"
            .sprintf('%07o', 0)."\0"
            .sprintf('%07o', 0)."\0"
            .sprintf('%011o', $size)."\0"
            .sprintf('%011o', $mtime)."\0"
            .'        '            // checksum placeholder: eight spaces
            .'0'                   // typeflag: a regular file
            .str_repeat("\0", 100) // linkname
            ."ustar\0".'00'
            .str_pad('', 32, "\0") // uname
            .str_pad('', 32, "\0") // gname
            .sprintf('%07o', 0)."\0"
            .sprintf('%07o', 0)."\0"
            .str_pad($prefix, 155, "\0");
        $header = str_pad($header, self::BLOCK, "\0");

        $sum = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $sum += ord($header[$i]);
        }

        return substr_replace($header, sprintf('%06o', $sum)."\0 ", 148, 8);
    }

    private function write(string $bytes): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('the archive is closed');
        }
        if (gzwrite($this->handle, $bytes) === false) {
            throw new RuntimeException('write to the archive failed');
        }
    }

    /** @param  resource  $handle */
    private static function readExactly($handle, int $length): ?string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = gzread($handle, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    private static function checksumMatches(string $header): bool
    {
        $stored = trim(substr($header, 148, 8), "\0 ");
        if ($stored === '') {
            return false;
        }
        $blanked = substr_replace($header, '        ', 148, 8);
        $sum = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $sum += ord($blanked[$i]);
        }

        return (int) octdec($stored) === $sum;
    }
}
