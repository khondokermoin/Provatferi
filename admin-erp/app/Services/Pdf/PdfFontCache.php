<?php

namespace App\Services\Pdf;

use Mpdf\Mpdf;

/**
 * Keeps mPDF's on-disk font cache (<tempDir>/mpdf/ttfontdata) trustworthy for the Bengali shaping fonts.
 *
 * WHY THIS EXISTS (found 2026-10-10 by comparing rendered pages against a HarfBuzz reference): mPDF shapes Bengali only from
 * the OpenType data it has parsed out of the font and cached per FONT KEY (`<key>.mtx.json`, `<key>.GSUB*.json`, …). Its
 * FontCache memoises every JSON file it reads for the life of the process but never forgets one it later rewrites. So when a
 * cache entry is REGENERATED while a stale copy exists — the font file changed, `useOTL` changed, or another configuration
 * wrote the same key — that document is built from the stale copy: no GSUB/GPOS script data, therefore no shaping. Split
 * vowel signs, visible hasantas and unformed conjuncts, in an otherwise healthy PDF; the next document is fine again. The
 * previous service flipped the same key between a shaped and an unshaped configuration (any exception, including an
 * unrelated oversized photo, entered the unshaped one), so the next real document after such an event came out broken —
 * "Bengali is not consistently right".
 *
 * What this class does about it:
 *   1. the shaping fonts have keys of their own that no other configuration ever writes (PdfRenderer), so a regeneration of
 *      an existing entry cannot happen in normal operation;
 *   2. a marker records a fingerprint of the font files + mPDF version, and `isReady()` also requires every cache file the
 *      shaping needs to exist and be complete — a marker can no longer claim a cache that is gone or half written;
 *   3. (re)building happens under an exclusive lock, from a clean slate, by a throw-away document that nobody downloads,
 *      and only counts once PdfRenderer has verified the result; documents render under a shared lock so a rebuild can
 *      never pull the files out from under one.
 *
 * Everything here is cheap on the hot path: one small file read and a handful of stat calls.
 */
final class PdfFontCache
{
    private const MARKER = '.pdf-fonts-ready';

    private const LOCK = '.pdf-fonts.lock';

    /** gid.dat of an OTL font is a fixed 256*256*3 byte map (glyph id → code point). */
    private const GID_BYTES = 196608;

    /**
     * @param  array<string, string>  $files  font key => absolute path of every font file the cache is built from
     * @param  list<string>  $shapedKeys  font keys registered with `useOTL` (the ones whose OpenType data must be cached)
     */
    public function __construct(
        private readonly string $tempDir,
        private readonly array $files,
        private readonly array $shapedKeys,
        private readonly string $version,
    ) {
    }

    public function dataDirectory(): string
    {
        return $this->baseDirectory().'/ttfontdata';
    }

    public function baseDirectory(): string
    {
        return rtrim($this->tempDir, '/\\').'/mpdf';
    }

    /** Changes when a font file, this class' version or mPDF itself changes. */
    public function fingerprint(): string
    {
        $parts = [$this->version, Mpdf::VERSION];
        foreach ($this->files as $key => $path) {
            $parts[] = [$key, basename($path), @filesize($path), @filemtime($path)];
        }

        return sha1((string) json_encode($parts));
    }

    /** @return list<string> */
    public function requiredFiles(): array
    {
        $files = [];
        foreach ($this->shapedKeys as $key) {
            foreach (['.mtx.json', '.cw.dat', '.gid.dat', '.GDEFdata.json', '.GPOSdata.json', '.GSUBdata.json', '.GSUBGPOStables.dat', '.GSUB.bng2.DFLT.json'] as $suffix) {
                $files[] = $this->dataDirectory().'/'.$key.$suffix;
            }
        }

        return $files;
    }

    public function isReady(): bool
    {
        $marker = json_decode((string) @file_get_contents($this->baseDirectory().'/'.self::MARKER), true);
        if (! is_array($marker) || ($marker['fingerprint'] ?? null) !== $this->fingerprint()) {
            return false;
        }

        foreach ($this->requiredFiles() as $file) {
            $size = @filesize($file);
            if ($size === false || $size <= 0) {
                return false;
            }
            if (str_ends_with($file, '.gid.dat') && $size !== self::GID_BYTES) {
                return false;
            }
        }

        return true;
    }

    /** @return array{ready: bool, fingerprint: string, directory: string, missing: list<string>} */
    public function status(): array
    {
        $missing = [];
        foreach ($this->requiredFiles() as $file) {
            if (! is_file($file) || filesize($file) <= 0) {
                $missing[] = basename($file);
            }
        }

        return ['ready' => $this->isReady(), 'fingerprint' => $this->fingerprint(), 'directory' => $this->dataDirectory(), 'missing' => $missing];
    }

    /** Forget that the cache was verified: the next document rebuilds it. */
    public function invalidate(): void
    {
        @unlink($this->baseDirectory().'/'.self::MARKER);
    }

    /** Remove every cache file of the shaping fonts' keys (and only those). */
    public function wipe(): void
    {
        foreach (array_keys($this->files) as $key) {
            foreach (glob($this->dataDirectory().'/'.$key.'*') ?: [] as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Build the cache once, under an exclusive lock, if it is not already verified. $build renders the throw-away document
     * and checks the result; it must throw when the result is not usable, so that no marker is ever written for it.
     */
    public function ensureReady(callable $build): void
    {
        if ($this->isReady()) {
            return;
        }

        $this->locked(LOCK_EX, function () use ($build): void {
            if ($this->isReady()) {
                return; // another process built it while this one waited for the lock
            }
            $this->invalidate();
            $this->wipe();
            $build();
            $this->writeMarker();
        });
    }

    /**
     * Run $fn while holding the shared lock: any number of documents at once, but never while the cache is being rebuilt.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function shared(callable $fn): mixed
    {
        return $this->locked(LOCK_SH, $fn);
    }

    private function writeMarker(): void
    {
        $contents = [];
        foreach ($this->files as $key => $path) {
            $contents[$key] = ['file' => basename($path), 'sha1' => @sha1_file($path)];
        }
        file_put_contents($this->baseDirectory().'/'.self::MARKER, json_encode([
            'fingerprint' => $this->fingerprint(),
            'built_at' => gmdate('c'),
            'mpdf' => Mpdf::VERSION,
            'fonts' => $contents,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function locked(int $mode, callable $fn): mixed
    {
        $base = $this->baseDirectory();
        if (! is_dir($base) && ! @mkdir($base, 0775, true) && ! is_dir($base)) {
            throw new PdfEngineException("The PDF temp directory {$base} cannot be created or written.");
        }
        $handle = @fopen($base.'/'.self::LOCK, 'c');
        if ($handle === false) {
            throw new PdfEngineException("The PDF temp directory {$base} is not writable (lock file).");
        }

        try {
            flock($handle, $mode);

            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
