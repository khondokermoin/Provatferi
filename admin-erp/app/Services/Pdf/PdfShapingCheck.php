<?php

namespace App\Services\Pdf;

use RuntimeException;

/**
 * The checks behind `php artisan pdf:self-check` and the PDF tests: are the font files the ones recorded, and does the engine
 * still shape every word of the shaping fixture the way it did when somebody last LOOKED at the rendered page.
 *
 * The fixture (resources/pdf-qa/bengali-shaping-cases.json) is the owner's word list plus every shaping feature a Bengali
 * document needs (reph, ya-/ra-phala, conjuncts, every vowel sign, ঁ ং ঃ ৎ ড় ঢ় য়, months, digits). The golden file next to it
 * records how many glyphs the engine draws for each word, regular and bold. Shaped Bengali draws fewer glyphs than it has code
 * points (a conjunct is one glyph, a pre-base vowel sign is reordered, ো is two glyphs), so a document that came out unshaped —
 * the failure this class guards against — changes the numbers; extracted text never would.
 *
 * What the golden numbers are NOT: a statement that the shaping is RIGHT. They record that it is what it was. That it was right
 * is established by deploy/qa/pdf-shaping-qa.mjs, which rasterises the engine's pages and compares them, pixel by pixel, with a
 * HarfBuzz rendering of the same font. Regenerate the golden file (`pdf:self-check --write-golden`) only after running that.
 */
final class PdfShapingCheck
{
    public const FIXTURE = 'pdf-qa/bengali-shaping-cases.json';

    public const GOLDEN = 'pdf-qa/bengali-shaping-glyphs.json';

    public const FONT_MANIFEST = 'fonts/FONTS.json';

    /** Every assigned Bengali code point a document can use: letters, signs, digits, ৳ — and the joiners. */
    private const BENGALI_RANGES = [[0x0981, 0x0983], [0x0985, 0x098C], [0x098F, 0x0990], [0x0993, 0x09A8], [0x09AA, 0x09B0], [0x09B2, 0x09B2], [0x09B6, 0x09B9], [0x09BC, 0x09C4], [0x09C7, 0x09C8], [0x09CB, 0x09CE], [0x09D7, 0x09D7], [0x09DC, 0x09DD], [0x09DF, 0x09E3], [0x09E6, 0x09F3], [0x0964, 0x0965], [0x200C, 0x200D]];

    /** What the Latin font must supply to the Bengali one: ASCII and the punctuation around names, dates and addresses. */
    private const LATIN_RANGES = [[0x20, 0x7E], [0xA0, 0xA0], [0xB7, 0xB7], [0x2013, 0x2014], [0x2018, 0x2019], [0x201C, 0x201D], [0x2022, 0x2022], [0x2026, 0x2026]];

    public function __construct(private readonly PdfRenderer $renderer)
    {
    }

    /** @return list<string> the Bengali-only tokens of every fixture entry (mixed Latin tokens are drawn by the Latin font and are not counted) */
    public function words(): array
    {
        $words = [];
        foreach ($this->fixture()['groups'] as $group) {
            foreach ($group['words'] as $entry) {
                foreach (preg_split('/\s+/u', $entry) ?: [] as $token) {
                    if ($token !== '' && preg_match('/[\x{0980}-\x{09FF}]/u', $token) && ! preg_match('/[A-Za-z]/', $token)) {
                        $words[$token] = true;
                    }
                }
            }
        }
        $words = array_keys($words);
        sort($words);

        return $words;
    }

    /** @return list<string> the fixture's words plus every Bengali word of the product's own strings (lang/bn/*.php): what a document can print */
    public function langWords(): array
    {
        $words = array_fill_keys($this->words(), true);
        $walk = function (mixed $value) use (&$walk, &$words): void {
            if (is_array($value)) {
                array_walk($value, fn ($item) => $walk($item));
            } elseif (is_string($value) && preg_match_all('/[\x{0980}-\x{09FF}\x{200C}\x{200D}]+/u', $value, $found)) {
                foreach ($found[0] as $word) {
                    $word = (string) preg_replace('/^[\x{200C}\x{200D}]+|[\x{200C}\x{200D}]+$/u', '', $word);
                    if (mb_strlen($word) >= 2 && mb_strlen($word) <= 18) {
                        $words[$word] = true;
                    }
                }
            }
        };
        foreach (glob(lang_path('bn/*.php')) ?: [] as $file) {
            $walk(require $file);
        }
        $words = array_keys($words);
        sort($words);

        return $words;
    }

    /**
     * Glyphs the engine draws with the Bengali font for each word, one word per page of one document per weight.
     *
     * @param  list<string>|null  $words
     * @return array{regular: array<string, int>, bold: array<string, int>}
     */
    public function measure(?array $words = null): array
    {
        $words ??= $this->words();
        $result = ['regular' => [], 'bold' => []];
        foreach (['regular' => '%s', 'bold' => '<b>%s</b>'] as $weight => $wrap) {
            $html = '';
            foreach ($words as $i => $word) {
                $html .= ($i > 0 ? '<pagebreak />' : '').'<div style="font-size: 20pt">'.sprintf($wrap, htmlspecialchars($word)).'</div>';
            }
            $counts = PdfInspector::glyphsPerPage($this->renderer->render($html, 'pdf-self-check'), 'Shaping');
            if (count($counts) !== count($words)) {
                throw new RuntimeException('The shaping fixture produced '.count($counts).' pages of text for '.count($words).' words.');
            }
            $result[$weight] = array_combine($words, $counts);
        }

        return $result;
    }

    /** @return list<string> every difference between what the engine draws now and the golden file (empty: identical) */
    public function mismatches(): array
    {
        $golden = $this->golden();
        $problems = [];
        if (($golden['mpdf'] ?? null) !== \Mpdf\Mpdf::VERSION) {
            $problems[] = 'mPDF is '.\Mpdf\Mpdf::VERSION.' but the golden glyph counts were recorded with '.($golden['mpdf'] ?? '?').'; look at the rendered pages (deploy/qa/pdf-shaping-qa.mjs), then regenerate.';
        }
        $manifest = $this->manifest();
        foreach (['bengali_regular' => 'NotoSansBengali-Shaping-Regular.ttf', 'bengali_bold' => 'NotoSansBengali-Shaping-Bold.ttf'] as $key => $file) {
            if (($golden['fonts'][$key] ?? null) !== $manifest[$file]['sha256']) {
                $problems[] = "{$file} is not the file the golden glyph counts were measured with: look at the rendered pages (deploy/qa/pdf-shaping-qa.mjs), then regenerate the golden file.";
            }
        }
        $measured = $this->measure(array_keys($golden['regular']));
        foreach (['regular', 'bold'] as $weight) {
            foreach ($golden[$weight] as $word => $expected) {
                $now = $measured[$weight][$word] ?? null;
                if ($now !== $expected) {
                    $problems[] = "{$weight} “{$word}”: ".($now ?? 'missing')." glyphs, expected {$expected}";
                }
            }
        }

        return $problems;
    }

    /** Record the engine's current glyph counts as the golden file. Developers only, after looking at the rendered pages. */
    public function writeGolden(): string
    {
        $measured = $this->measure();
        $manifest = $this->manifest();
        $golden = [
            '_comment' => 'Glyphs the PDF engine draws for each Bengali word of bengali-shaping-cases.json (php artisan pdf:self-check --write-golden). A signature of shaping, not proof of it: see app/Services/Pdf/PdfShapingCheck.php.',
            'mpdf' => \Mpdf\Mpdf::VERSION,
            // The hashes of the Bengali font files these counts were measured with (key names avoid the word "shaping": a secret
            // scanner reads "…api…" in a key followed by a long hex string as an API key).
            'fonts' => [
                'bengali_regular' => $manifest['NotoSansBengali-Shaping-Regular.ttf']['sha256'],
                'bengali_bold' => $manifest['NotoSansBengali-Shaping-Bold.ttf']['sha256'],
            ],
            'regular' => $measured['regular'],
            'bold' => $measured['bold'],
        ];
        $path = resource_path(self::GOLDEN);
        file_put_contents($path, json_encode($golden, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");

        return $path;
    }

    /**
     * Is every font file the one recorded in FONTS.json, readable, complete and able to do its job?
     *
     * @return list<string> problems (empty: all good)
     */
    public function fontProblems(): array
    {
        $problems = [];
        $manifest = $this->manifest();
        $names = [];

        if (! $this->renderer->hasSymbolsFont()) {
            $problems[] = 'DejaVu Sans is missing from mPDF\'s font directory (vendor/mpdf/mpdf/ttfonts): ✓ ✗ would be left out of the documents';
        }

        foreach ($manifest as $file => $info) {
            $path = resource_path('fonts/'.$file);
            if (! is_file($path)) {
                $problems[] = "{$file} is missing";

                continue;
            }
            $audit = PdfFontAudit::inspect($path);
            if ($audit['sha256'] !== $info['sha256']) {
                $problems[] = "{$file} is not the recorded file (sha256 differs) — a font swapped, edited or re-exported must be re-verified visually and the manifest regenerated (deploy/fonts-manifest.mjs)";
            }
            foreach ($info['tables_sha256'] as $tag => $hash) {
                if (($audit['tables'][$tag]['sha256'] ?? null) !== $hash) {
                    $problems[] = "{$file}: table {$tag} differs from the recorded one";
                }
            }
            $postscript = $audit['names'][6] ?? '';
            if ($postscript !== $info['postscript_name']) {
                $problems[] = "{$file}: PostScript name is “{$postscript}”, recorded “{$info['postscript_name']}”";
            }
            if (isset($names[$postscript])) {
                $problems[] = "{$file} and {$names[$postscript]} share the PostScript name “{$postscript}”: two font programs in one PDF would carry the same name";
            }
            $names[$postscript] = $file;

            $bold = str_contains($file, 'Bold');
            if ($audit['weight'] !== ($bold ? 700 : 400)) {
                $problems[] = "{$file}: OS/2 weight class is {$audit['weight']}, expected ".($bold ? 700 : 400);
            }

            if (str_contains($file, 'Shaping')) {
                foreach (['GSUB', 'GPOS', 'GDEF'] as $tag) {
                    if (! isset($audit['layout'][$tag])) {
                        $problems[] = "{$file}: no {$tag} table — the font cannot be shaped";
                    }
                }
                if (! in_array('bng2', $audit['layout']['GSUB']['scripts'] ?? [], true) || ($audit['layout']['GSUB']['lookups'] ?? 0) < 1) {
                    $problems[] = "{$file}: no GSUB lookups for the Bengali script (bng2)";
                }
                if (($audit['layout']['GDEF']['version'] ?? '') !== '1.0') {
                    $problems[] = "{$file}: GDEF is version ".($audit['layout']['GDEF']['version'] ?? '?').'; mPDF 8.3.1 reads 1.0 only (1.2 is read at the wrong offset)';
                }
                $missing = PdfFontAudit::missingCodePoints($path, $this->expand(self::BENGALI_RANGES));
                if ($missing !== []) {
                    $problems[] = "{$file}: no glyph for ".$this->codePoints($missing);
                }
            } else {
                $missing = PdfFontAudit::missingCodePoints($path, $this->expand(self::LATIN_RANGES));
                if ($missing !== []) {
                    $problems[] = "{$file}: no glyph for ".$this->codePoints($missing);
                }
            }
        }

        // The browser print view's fonts: the same typeface the PDF's Latin comes from, recorded by hash.
        foreach ($this->json(resource_path(self::FONT_MANIFEST))['print_fonts'] ?? [] as $file => $info) {
            $path = public_path('brand/fonts/'.$file);
            if (! is_file($path)) {
                $problems[] = "print font {$file} is missing";
            } elseif (hash_file('sha256', $path) !== $info['sha256']) {
                $problems[] = "print font {$file} is not the recorded file — rebuild it with deploy/build-print-font.mjs and check it (docs/PDF_BENGALI_STANDARD.md), then regenerate the manifest";
            }
        }

        return $problems;
    }

    /** @return array<string, array<string, mixed>> the fonts the PDF engine embeds, by file name */
    public function manifest(): array
    {
        return $this->json(resource_path(self::FONT_MANIFEST))['fonts'];
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return $this->json(resource_path(self::FIXTURE));
    }

    /** @return array<string, mixed> */
    private function golden(): array
    {
        return $this->json(resource_path(self::GOLDEN));
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $data = json_decode((string) @file_get_contents($path), true);
        if (! is_array($data)) {
            throw new RuntimeException("{$path} is missing or not valid JSON.");
        }

        return $data;
    }

    /**
     * @param  list<array{int, int}>  $ranges
     * @return list<int>
     */
    private function expand(array $ranges): array
    {
        $all = [];
        foreach ($ranges as [$from, $to]) {
            for ($cp = $from; $cp <= $to; $cp++) {
                $all[] = $cp;
            }
        }

        return $all;
    }

    /** @param list<int> $codePoints */
    private function codePoints(array $codePoints): string
    {
        return implode(' ', array_map(fn (int $cp) => sprintf('U+%04X', $cp), $codePoints));
    }
}
