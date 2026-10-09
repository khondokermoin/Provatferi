<?php

namespace App\Services\Pdf;

use Illuminate\Support\Facades\Log;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * THE Bengali PDF standard of the admin: one engine (mPDF), one typography configuration, used by every generated document
 * (payment receipts, recruitment application copies — see docs/PDF_BENGALI_STANDARD.md for the audit and the rules).
 *
 *   • Bengali:  Noto Sans Bengali 2.001 (resources/fonts/NotoSansBengali-Shaping-*.ttf) with mPDF's OpenType layout ON
 *               (`useOTL`): reordering of pre-base vowel signs, reph, and the conjunct/half forms from the font's GSUB/GPOS.
 *               The 3.x release of the same typeface cannot be read by mPDF 8.3.1 (GDEF 1.2, lookup formats it rejects).
 *   • Latin, digits, punctuation the 2.001 file lacks: Noto Sans Bengali 3.011 (the same typeface the browser print view
 *               uses), through mPDF's glyph substitution.   • ✓ ✗ and other symbols: DejaVu Sans from the mPDF package.
 *   • Every font is embedded as a subset; nothing is fetched or taken from the viewer's machine. The three families have
 *               font keys of their own (FONT_*) that nothing else ever writes — see PdfFontCache for why that matters.
 *   • There is NO unshaped fallback. A configuration that cannot shape Bengali throws PdfEngineException; it never hands
 *               out a document with split vowel signs and unformed conjuncts (that fallback, together with an mPDF
 *               cache flaw, was the cause of "Bengali is right, then wrong, then right again").
 *   • Images go in through mPDF's `var:` images, never as base64 in the HTML: a few hundred KB of base64 pushes the HTML over
 *               pcre.backtrack_limit and mPDF refuses it. Photos are made PDF-sized by PdfImagePreparer first.
 *
 * mPDF's autoScriptToLang/autoLangToFont stay OFF: with a custom font registered they were seen to route bold text to a
 * core font with no Bengali glyphs (tofu). Do not enable them without re-running the visual QA (deploy/qa/pdf-shaping-qa.mjs).
 *
 * Look at a rendered page after ANY change here. Extracted text proves nothing about shaping.
 */
final class PdfRenderer
{
    /** mPDF font keys == the CSS font-family names the document templates use. */
    public const FONT_BENGALI = 'provatferibn';

    public const FONT_LATIN = 'provatferilatin';

    public const FONT_SYMBOLS = 'provatferisymbols';

    /** Bump when a shaping font file or the font configuration changes: forces the cache to be rebuilt and re-verified. */
    private const CACHE_VERSION = '3';

    /** Throw-away document that makes mPDF parse and cache every font of the configuration, bold included. */
    private const PROBE_HTML = '<p>পরিশোধ ক্ষ ত্য অক্টোবর Abc ০১২ ✓</p><p><b>পরিশোধ ক্ষ ত্য অক্টোবর Abc ০১২ ✓</b></p>';

    private ?PdfFontCache $cache = null;

    /** @param  string|null  $tempDir  mPDF's temp/cache directory; null = mPDF's own (vendor/mpdf/mpdf/tmp). Tests pass a private one. */
    public function __construct(private readonly ?string $tempDir = null)
    {
    }

    /**
     * @param  string  $html  the document (a body fragment with its own <style>; images as src="var:name")
     * @param  array<string, string>  $images  name => binary image, referenced from $html as src="var:name"
     *
     * @throws PdfEngineException when a correctly shaped document cannot be produced
     */
    public function render(string $html, string $title = 'Provatferi ERP', array $images = []): string
    {
        $this->guardSize($html);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->cache()->ensureReady(fn () => $this->buildCache());

            $result = $this->cache()->shared(function () use ($html, $title, $images) {
                if (! $this->cache()->isReady()) {
                    return null; // a rebuild started between the check and the lock: go round again
                }
                $mpdf = $this->makeMpdf();
                $problem = $this->shapingProblem($mpdf);
                if ($problem !== null) {
                    return $problem;
                }

                $mpdf->SetTitle($title);
                $mpdf->SetCreator('Provatferi ERP');
                foreach ($images as $name => $bytes) {
                    $mpdf->imageVars[$name] = $bytes;
                }
                $mpdf->WriteHTML($html);

                return ['pdf' => $mpdf->Output('', Destination::STRING_RETURN)];
            });

            if (is_array($result)) {
                return $result['pdf'];
            }
            if (is_string($result)) {
                // mPDF's in-memory view of the Bengali font is stale or incomplete: the document would be unshaped.
                Log::warning("PDF: font cache rejected, rebuilding (attempt {$attempt}): {$result}");
                $this->cache()->invalidate();
            }
        }

        Log::error('PDF: the Bengali shaping fonts could not be made ready; no document was produced.');

        throw new PdfEngineException('The PDF engine could not prepare the Bengali fonts. See the application log.');
    }

    /**
     * A verified, shaping-ready mPDF instance for callers that drive mPDF themselves (the visual QA harness, tests). The
     * caller owns the document; $overrides replace mPDF config keys such as 'format' or the margins.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function newMpdf(array $overrides = []): Mpdf
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->cache()->ensureReady(fn () => $this->buildCache());
            $mpdf = $this->makeMpdf($overrides);
            $problem = $this->shapingProblem($mpdf);
            if ($problem === null) {
                return $mpdf;
            }
            $this->cache()->invalidate();
        }

        throw new PdfEngineException('The PDF engine could not prepare the Bengali fonts.');
    }

    /** @return array{ready: bool, fingerprint: string, directory: string, missing: list<string>} */
    public function cacheStatus(): array
    {
        return $this->cache()->status();
    }

    /** The font files this configuration embeds, by font key (also what the cache fingerprint covers). */
    public function fontFiles(): array
    {
        $files = [
            self::FONT_BENGALI => resource_path('fonts/NotoSansBengali-Shaping-Regular.ttf'),
            self::FONT_BENGALI.'B' => resource_path('fonts/NotoSansBengali-Shaping-Bold.ttf'),
            self::FONT_LATIN => resource_path('fonts/NotoSansBengali-Regular.ttf'),
            self::FONT_LATIN.'B' => resource_path('fonts/NotoSansBengali-Bold.ttf'),
        ];
        if (($symbols = $this->symbolsFontDirectory()) !== null) {
            $files[self::FONT_SYMBOLS] = $symbols.'/DejaVuSans.ttf';
            $files[self::FONT_SYMBOLS.'B'] = $symbols.'/DejaVuSans-Bold.ttf';
        }

        return $files;
    }

    /**
     * mPDF's configuration — the one place the typography of every PDF is decided.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function config(array $overrides = []): array
    {
        $defaults = (new ConfigVariables())->getDefaults();
        $fontDefaults = (new FontVariables())->getDefaults();

        $fontdata = $fontDefaults['fontdata'] + [
            self::FONT_BENGALI => ['R' => 'NotoSansBengali-Shaping-Regular.ttf', 'B' => 'NotoSansBengali-Shaping-Bold.ttf', 'useOTL' => 0xFF],
            self::FONT_LATIN => ['R' => 'NotoSansBengali-Regular.ttf', 'B' => 'NotoSansBengali-Bold.ttf'],
        ];
        $substitutes = [self::FONT_LATIN];
        if ($this->symbolsFontDirectory() !== null) {
            $fontdata[self::FONT_SYMBOLS] = ['R' => 'DejaVuSans.ttf', 'B' => 'DejaVuSans-Bold.ttf'];
            $substitutes[] = self::FONT_SYMBOLS;
        }

        return array_replace([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => 15,
            'margin_bottom' => 15,
            'margin_left' => 15,
            'margin_right' => 15,
            'tempDir' => $this->tempDir ?? $defaults['tempDir'],
            'fontDir' => array_merge($defaults['fontDir'], [resource_path('fonts')]),
            'fontdata' => $fontdata,
            'default_font' => self::FONT_BENGALI,
            // Characters the Bengali file lacks (Latin, ✓ ✗, …) come from these, in this order.
            'useSubstitutions' => true,
            'backupSubsFont' => $substitutes,
            // Deliberately off — see the class comment.
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
        ], $overrides);
    }

    private function cache(): PdfFontCache
    {
        return $this->cache ??= new PdfFontCache(
            (string) $this->config()['tempDir'],
            $this->fontFiles(),
            [self::FONT_BENGALI, self::FONT_BENGALI.'B'],
            self::CACHE_VERSION,
        );
    }

    /** @param array<string, mixed> $overrides */
    private function makeMpdf(array $overrides = []): Mpdf
    {
        $mpdf = new Mpdf($this->config($overrides));

        // mPDF's glyph substitution tries the PDF CORE fonts first (Helvetica for · – — quotes, ZapfDingbats for ✓ ✗): those
        // are never embedded, so a viewer would draw them from whatever it has — "no dependence on viewer fonts" would be
        // false for exactly the characters that sit next to Bengali. Handing mPDF empty core tables makes it fall through
        // to the embedded fonts (backupSubsFont) instead. (It loads data/subs_core.php only while this property is empty.)
        $mpdf->subArrMB = ['a' => [], 's' => [], 'z' => []];

        return $mpdf;
    }

    /** Parse and cache every font once, then prove with a FRESH instance that what mPDF now reads is complete. */
    private function buildCache(): void
    {
        $probe = $this->makeMpdf();
        $probe->WriteHTML(self::PROBE_HTML);
        $probe->Output('', Destination::STRING_RETURN);

        // The probe is not trusted: it may itself have been built from a stale in-memory copy (PdfFontCache).
        $problem = $this->shapingProblem($this->makeMpdf());
        if ($problem !== null) {
            Log::error("PDF: the Bengali font cache failed verification after a rebuild: {$problem}");

            throw new PdfEngineException("The Bengali shaping fonts could not be prepared: {$problem}");
        }
    }

    /**
     * Would a document built from this instance shape Bengali? mPDF shapes from the OpenType data in its font state; that
     * state being empty is exactly what an unshaped document looks like. Returns the reason, or null when it is complete.
     */
    private function shapingProblem(Mpdf $mpdf): ?string
    {
        foreach (['' => 'NotoSansBengali-Shaping-Regular.ttf', 'B' => 'NotoSansBengali-Shaping-Bold.ttf'] as $style => $file) {
            $mpdf->AddFont(self::FONT_BENGALI, $style); // already registered for the regular style; the bold one is loaded lazily
            $key = self::FONT_BENGALI.$style;
            $font = $mpdf->fonts[$key] ?? null;

            if ($font === null) {
                return "font {$key} is not registered";
            }
            if (basename((string) ($font['ttffile'] ?? '')) !== $file) {
                return "font {$key} resolves to ".basename((string) ($font['ttffile'] ?? '?'))." instead of {$file}";
            }
            if (((int) ($font['useOTL'] ?? 0) & 0xFF) === 0) {
                return "font {$key} has OpenType layout switched off";
            }
            if (empty($font['GSUBScriptLang']['bng2'])) {
                return "font {$key} carries no GSUB data for the Bengali script (bng2)";
            }
            if (count($font['GSUBLookups'] ?? []) === 0 || empty($font['GPOSScriptLang'])) {
                return "font {$key} carries no GSUB/GPOS lookups";
            }
            if (strlen((string) ($font['glyphIDtoUni'] ?? '')) !== 196608) {
                return "font {$key} has no glyph map";
            }
        }

        return null;
    }

    private function guardSize(string $html): void
    {
        $limit = (int) ini_get('pcre.backtrack_limit');
        if ($limit > 0 && strlen($html) >= $limit) {
            throw new PdfEngineException('The document HTML ('.strlen($html)." bytes) is larger than mPDF can parse ({$limit}). Do not embed images as base64: pass them as render() images.");
        }
    }

    /** Is DejaVu Sans (✓ ✗ …) available from mPDF's own font directory on this install? */
    public function hasSymbolsFont(): bool
    {
        return $this->symbolsFontDirectory() !== null;
    }

    /** The mPDF font directory holding DejaVu Sans (✓ ✗ …), or null when this install lacks it. */
    private function symbolsFontDirectory(): ?string
    {
        foreach ((new ConfigVariables())->getDefaults()['fontDir'] as $dir) {
            if (is_file($dir.'/DejaVuSans.ttf') && is_file($dir.'/DejaVuSans-Bold.ttf')) {
                return rtrim((string) $dir, '/\\');
            }
        }

        return null;
    }
}
