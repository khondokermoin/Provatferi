<?php

namespace App\Services\Pdf;

/**
 * Reads a TrueType font file far enough to answer "is this the font we think it is, and can it do what the PDF needs": its
 * tables and their hashes, its names, the OpenType layout tables that carry the Bengali shaping (GSUB/GPOS/GDEF: version,
 * scripts, lookups), and which code points its cmap covers. Used by the tests and `php artisan pdf:self-check`; the recorded
 * truth lives in resources/fonts/FONTS.json (written by deploy/fonts-manifest.mjs).
 */
final class PdfFontAudit
{
    /**
     * @return array{bytes: int, sha256: string, tables: array<string, array{offset: int, length: int, sha256: string}>, names: array<int, string>, glyphs: int, unitsPerEm: int, weight: int, layout: array<string, array{version: string, scripts: list<string>, lookups: int}>}
     */
    public static function inspect(string $path): array
    {
        $bytes = (string) file_get_contents($path);
        $tables = self::tables($bytes);

        $layout = [];
        foreach (['GSUB', 'GPOS'] as $tag) {
            if (isset($tables[$tag])) {
                $layout[$tag] = self::layoutSummary($bytes, $tables[$tag]['offset']);
            }
        }
        if (isset($tables['GDEF'])) {
            $layout['GDEF'] = ['version' => self::u16($bytes, $tables['GDEF']['offset']).'.'.self::u16($bytes, $tables['GDEF']['offset'] + 2), 'scripts' => [], 'lookups' => 0];
        }

        return [
            'bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'tables' => $tables,
            'names' => self::names($bytes, $tables),
            'glyphs' => isset($tables['maxp']) ? self::u16($bytes, $tables['maxp']['offset'] + 4) : 0,
            'unitsPerEm' => isset($tables['head']) ? self::u16($bytes, $tables['head']['offset'] + 18) : 0,
            'weight' => isset($tables['OS/2']) ? self::u16($bytes, $tables['OS/2']['offset'] + 4) : 0,
            'layout' => $layout,
        ];
    }

    /**
     * Code points the font has NO glyph for.
     *
     * @param  list<int>  $codePoints
     * @return list<int>
     */
    public static function missingCodePoints(string $path, array $codePoints): array
    {
        $bytes = (string) file_get_contents($path);
        $has = self::cmapLookup($bytes, self::tables($bytes));

        return array_values(array_filter($codePoints, fn (int $cp) => ! $has($cp)));
    }

    /** @return array<string, array{offset: int, length: int, sha256: string}> */
    private static function tables(string $bytes): array
    {
        $count = self::u16($bytes, 4);
        $tables = [];
        for ($i = 0; $i < $count; $i++) {
            $o = 12 + $i * 16;
            $offset = self::u32($bytes, $o + 8);
            $length = self::u32($bytes, $o + 12);
            $tables[substr($bytes, $o, 4)] = ['offset' => $offset, 'length' => $length, 'sha256' => hash('sha256', substr($bytes, $offset, $length))];
        }

        return $tables;
    }

    /** @return array<int, string> Windows/English name records by id */
    private static function names(string $bytes, array $tables): array
    {
        if (! isset($tables['name'])) {
            return [];
        }
        $base = $tables['name']['offset'];
        $count = self::u16($bytes, $base + 2);
        $strings = $base + self::u16($bytes, $base + 4);
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $r = $base + 6 + $i * 12;
            if (self::u16($bytes, $r) === 3 && self::u16($bytes, $r + 4) === 0x409) {
                $names[self::u16($bytes, $r + 6)] = (string) mb_convert_encoding(substr($bytes, $strings + self::u16($bytes, $r + 10), self::u16($bytes, $r + 8)), 'UTF-8', 'UTF-16BE');
            }
        }

        return $names;
    }

    /** @return array{version: string, scripts: list<string>, lookups: int} */
    private static function layoutSummary(string $bytes, int $base): array
    {
        $scriptList = $base + self::u16($bytes, $base + 4);
        $lookupList = $base + self::u16($bytes, $base + 8);
        $scripts = [];
        for ($i = 0, $n = self::u16($bytes, $scriptList); $i < $n; $i++) {
            $scripts[] = substr($bytes, $scriptList + 2 + $i * 6, 4);
        }

        return ['version' => self::u16($bytes, $base).'.'.self::u16($bytes, $base + 2), 'scripts' => $scripts, 'lookups' => self::u16($bytes, $lookupList)];
    }

    /** @return callable(int): bool */
    private static function cmapLookup(string $bytes, array $tables): callable
    {
        $cmap = $tables['cmap']['offset'];
        $best = null;
        for ($i = 0, $n = self::u16($bytes, $cmap + 2); $i < $n; $i++) {
            $r = $cmap + 4 + $i * 8;
            $platform = self::u16($bytes, $r);
            $encoding = self::u16($bytes, $r + 2);
            $offset = $cmap + self::u32($bytes, $r + 4);
            $format = self::u16($bytes, $offset);
            if (($platform === 3 && $encoding === 10 && $format === 12) || ($best === null && in_array($format, [4, 12], true) && ($platform === 0 || ($platform === 3 && $encoding === 1)))) {
                $best = $offset;
            }
        }
        if ($best === null) {
            return fn (int $cp): bool => false;
        }
        $format = self::u16($bytes, $best);

        if ($format === 12) {
            $groups = self::u32($bytes, $best + 12);

            return function (int $cp) use ($bytes, $best, $groups): bool {
                for ($g = 0; $g < $groups; $g++) {
                    $o = $best + 16 + $g * 12;
                    if ($cp >= self::u32($bytes, $o) && $cp <= self::u32($bytes, $o + 4)) {
                        return self::u32($bytes, $o + 8) + ($cp - self::u32($bytes, $o)) !== 0;
                    }
                }

                return false;
            };
        }

        $segments = intdiv(self::u16($bytes, $best + 6), 2);
        $ends = $best + 14;
        $starts = $ends + $segments * 2 + 2;
        $deltas = $starts + $segments * 2;
        $ranges = $deltas + $segments * 2;

        return function (int $cp) use ($bytes, $segments, $ends, $starts, $deltas, $ranges): bool {
            if ($cp > 0xFFFF) {
                return false;
            }
            for ($s = 0; $s < $segments; $s++) {
                if (self::u16($bytes, $ends + $s * 2) < $cp) {
                    continue;
                }
                $start = self::u16($bytes, $starts + $s * 2);
                if ($cp < $start) {
                    return false;
                }
                $delta = self::u16($bytes, $deltas + $s * 2);
                $rangeOffset = self::u16($bytes, $ranges + $s * 2);
                if ($rangeOffset === 0) {
                    return (($cp + $delta) & 0xFFFF) !== 0;
                }
                $glyph = self::u16($bytes, $ranges + $s * 2 + $rangeOffset + ($cp - $start) * 2);

                return $glyph !== 0 && (($glyph + $delta) & 0xFFFF) !== 0;
            }

            return false;
        };
    }

    private static function u16(string $bytes, int $offset): int
    {
        return (ord($bytes[$offset]) << 8) | ord($bytes[$offset + 1]);
    }

    private static function u32(string $bytes, int $offset): int
    {
        return (self::u16($bytes, $offset) << 16) | self::u16($bytes, $offset + 2);
    }
}
