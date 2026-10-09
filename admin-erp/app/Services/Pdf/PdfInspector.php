<?php

namespace App\Services\Pdf;

/**
 * Reads the structure of a PDF produced by PdfRenderer: which fonts it embeds and how many glyphs each text run draws.
 *
 * It exists for tests and the self-check, and it is deliberately limited: it understands exactly what mPDF writes
 * (Identity-H CID fonts shown with literal-string Tj/TJ operators, Flate-compressed streams). The glyph counts are the
 * cheap, deterministic signature of shaping — a conjunct such as ক্ষ is three code points but ONE glyph, and a vowel sign
 * that was split or reordered changes the count — but they are NOT a substitute for looking at the page: the visual QA
 * (deploy/qa/pdf-shaping-qa.mjs) compares rendered pixels against a HarfBuzz reference.
 */
final class PdfInspector
{
    /**
     * @return array{fonts: array<string, array{base: string, embedded: bool, subtype: string}>, runs: list<array{font: string, base: string, embedded: bool, size: float, glyphs: int, codes: list<int>}>, pages: list<list<array{font: string, base: string, embedded: bool, size: float, glyphs: int, codes: list<int>}>>}
     */
    public static function analyse(string $pdf): array
    {
        $objects = self::objects($pdf);

        // Fonts, resolved to their descriptor so "embedded" means a font program really is in the file.
        $fonts = [];
        foreach ($objects as $number => $object) {
            $dict = $object['dict'];
            if (! preg_match('#/Type\s*/Font\b#', $dict) || preg_match('#/Subtype\s*/CIDFontType2#', $dict)) {
                continue;
            }
            preg_match('#/Subtype\s*/(\w+)#', $dict, $subtype);
            preg_match('#/BaseFont\s*/([^\s/\[\]<>()]+)#', $dict, $base);
            $embedded = false;
            if (($subtype[1] ?? '') === 'Type0' && preg_match('#/DescendantFonts\s*\[\s*(\d+)\s+0\s+R#', $dict, $descendant)) {
                $cid = $objects[(int) $descendant[1]]['dict'] ?? '';
                if (preg_match('#/FontDescriptor\s+(\d+)\s+0\s+R#', $cid, $descriptor)) {
                    $embedded = (bool) preg_match('#/FontFile[23]?\s+\d+\s+0\s+R#', $objects[(int) $descriptor[1]]['dict'] ?? '');
                }
            }
            if (($subtype[1] ?? '') !== 'Type0' && preg_match('#/FontDescriptor\s+(\d+)\s+0\s+R#', $dict, $descriptor)) {
                // A simple TrueType font (mPDF writes one for fonts without OpenType layout, e.g. the DejaVu symbols).
                $embedded = (bool) preg_match('#/FontFile[23]?\s+\d+\s+0\s+R#', $objects[(int) $descriptor[1]]['dict'] ?? '');
            }
            $fonts[$number] = ['base' => $base[1] ?? '?', 'embedded' => $embedded, 'subtype' => $subtype[1] ?? '?'];
        }

        // Resource names (/F1 …) → font objects.
        $names = [];
        foreach ($objects as $object) {
            if (preg_match_all('#/Font\s*<<([^>]*)>>#', $object['dict'], $dicts)) {
                foreach ($dicts[1] as $body) {
                    if (preg_match_all('#/(F\d+)\s+(\d+)\s+0\s+R#', $body, $pairs, PREG_SET_ORDER)) {
                        foreach ($pairs as $pair) {
                            $names[$pair[1]] = (int) $pair[2];
                        }
                    }
                }
            }
        }
        $byName = [];
        foreach ($names as $name => $number) {
            if (isset($fonts[$number])) {
                $byName[$name] = $fonts[$number];
            }
        }

        $runs = [];
        $pages = [];
        foreach ($objects as $object) {
            $content = $object['stream'];
            if ($content === null || ! str_contains($content, ' Tf')) {
                continue;
            }
            $pageRuns = [];
            $font = null;
            $size = 0.0;
            // Tf selects a font; (…) Tj and [ … ] TJ draw with it.
            preg_match_all('#/(F\d+)\s+([\d.]+)\s+Tf|(\((?:\\\\.|[^\\\\()])*\))\s*Tj|\[((?:\((?:\\\\.|[^\\\\()])*\)|[^\]()])*)\]\s*TJ#s', $content, $tokens, PREG_SET_ORDER);
            foreach ($tokens as $token) {
                if (($token[1] ?? '') !== '') {
                    $font = $token[1];
                    $size = (float) $token[2];

                    continue;
                }
                $strings = [];
                if (($token[3] ?? '') !== '') {
                    $strings[] = $token[3];
                } elseif (isset($token[4])) {
                    preg_match_all('#\((?:\\\\.|[^\\\\()])*\)#s', $token[4], $inside);
                    $strings = $inside[0];
                }
                // A Type0/Identity-H font shows two bytes per glyph; a core (Type1) font one byte per character.
                $wide = ($byName[$font ?? '']['subtype'] ?? '') === 'Type0';
                $codes = [];
                foreach ($strings as $literal) {
                    $bytes = self::unescape(substr($literal, 1, -1));
                    if ($wide) {
                        for ($i = 0; $i + 1 < strlen($bytes); $i += 2) {
                            $codes[] = (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]);
                        }
                    } else {
                        foreach (str_split($bytes) as $byte) {
                            $codes[] = ord($byte);
                        }
                    }
                }
                if ($codes !== [] && $font !== null) {
                    $pageRuns[] = ['font' => $font, 'base' => $byName[$font]['base'] ?? '?', 'embedded' => $byName[$font]['embedded'] ?? false, 'size' => $size, 'glyphs' => count($codes), 'codes' => $codes];
                }
            }
            if ($pageRuns !== []) {
                $pages[] = $pageRuns;
                $runs = array_merge($runs, $pageRuns);
            }
        }

        return ['fonts' => $byName, 'runs' => $runs, 'pages' => $pages];
    }

    /**
     * Glyphs drawn per page with the fonts whose name contains $fontNeedle (e.g. "Shaping" = the Bengali font), one entry
     * per page that has text: a document with one fixture word per page yields one count per word, in one render.
     *
     * @return list<int>
     */
    public static function glyphsPerPage(string $pdf, string $fontNeedle): array
    {
        $counts = [];
        foreach (self::analyse($pdf)['pages'] as $runs) {
            $counts[] = array_sum(array_map(fn (array $run) => str_contains($run['base'], $fontNeedle) ? $run['glyphs'] : 0, $runs));
        }

        return $counts;
    }

    /**
     * The images in the file: pixel size and filter (DCTDecode = a JPEG kept as it was).
     *
     * @return list<array{width: int, height: int, filter: string}>
     */
    public static function images(string $pdf): array
    {
        $images = [];
        foreach (self::objects($pdf) as $object) {
            if (preg_match('#/Subtype\s*/Image\b#', $object['dict']) && preg_match('#/Width\s+(\d+)#', $object['dict'], $w) && preg_match('#/Height\s+(\d+)#', $object['dict'], $h)) {
                preg_match('#/Filter\s*/(\w+)#', $object['dict'], $filter);
                $images[] = ['width' => (int) $w[1], 'height' => (int) $h[1], 'filter' => $filter[1] ?? ''];
            }
        }

        return $images;
    }

    /**
     * Where each image is drawn and how big, in pt: [[width, height], …] from the page content's `q w 0 0 h x y cm /Ix Do Q`.
     *
     * @return list<array{float, float}>
     */
    public static function imagePlacements(string $pdf): array
    {
        $placements = [];
        foreach (self::objects($pdf) as $object) {
            if ($object['stream'] !== null && preg_match_all('#q\s+([\d.]+)\s+0\s+0\s+([\d.]+)\s+[\d.-]+\s+[\d.-]+\s+cm\s+/I\d+\s+Do\s+Q#', $object['stream'], $found, PREG_SET_ORDER)) {
                foreach ($found as $match) {
                    $placements[] = [(float) $match[1], (float) $match[2]];
                }
            }
        }

        return $placements;
    }

    /** Base names of every font object the document draws text with. */
    public static function usedFonts(string $pdf): array
    {
        return array_values(array_unique(array_map(fn (array $run) => $run['base'], self::analyse($pdf)['runs'])));
    }

    /** Base names of fonts that text is drawn with although no font program is embedded (a viewer substitutes its own). */
    public static function usedUnembeddedFonts(string $pdf): array
    {
        $names = [];
        foreach (self::analyse($pdf)['runs'] as $run) {
            if (! $run['embedded']) {
                $names[$run['base']] = true;
            }
        }

        return array_keys($names);
    }

    /** @return list<float> the distinct font sizes (pt) text is drawn at, in order of first use */
    public static function usedSizes(string $pdf): array
    {
        $sizes = [];
        foreach (self::analyse($pdf)['runs'] as $run) {
            $sizes[(string) $run['size']] = $run['size'];
        }

        return array_values($sizes);
    }

    /** @return array<int, array{dict: string, stream: ?string}> */
    private static function objects(string $pdf): array
    {
        $objects = [];
        if (! preg_match_all('#(\d+)\s+0\s+obj\s*(.*?)\s*endobj#s', $pdf, $matches, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($matches as $match) {
            $body = $match[2];
            $dict = $body;
            $stream = null;
            if (str_contains($body, 'stream') && preg_match('#stream\r?\n#', $body, $start, PREG_OFFSET_CAPTURE)) {
                $dict = substr($body, 0, $start[0][1]);
                $data = substr($body, $start[0][1] + strlen($start[0][0]));
                // Exactly the one "\n" mPDF writes before endstream: Flate data may itself end in a CR or LF byte.
                $data = preg_replace('#\nendstream\s*$#', '', $data) ?? $data;
                if (str_contains($dict, 'FlateDecode')) {
                    $decoded = @gzuncompress($data);
                    $data = $decoded === false ? '' : $decoded;
                }
                $stream = $data;
            }
            $objects[(int) $match[1]] = ['dict' => $dict, 'stream' => $stream];
        }

        return $objects;
    }

    private static function unescape(string $escaped): string
    {
        return strtr($escaped, ['\\)' => ')', '\\(' => '(', '\\\\' => '\\', '\\r' => "\r"]);
    }
}
