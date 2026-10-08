<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Throwable;

/**
 * Renders application-document HTML to a PDF that actually forms Bengali
 * conjuncts correctly. This is not a detail — it's the whole reason mPDF was
 * chosen over dompdf here: dompdf has no OpenType GSUB/GPOS shaping engine at
 * all, so it CANNOT join Bengali consonant clusters (ক্ষ, জ্ঞ, স্ব, ত্ত্ব) —
 * it draws each codepoint's glyph independently regardless of font. mPDF
 * ships its own complex-script shaping engine.
 *
 * THAT ENGINE IS OFF UNLESS THE FONT ASKS FOR IT, and everything below exists because of it (found 2026-10-09 by
 * LOOKING at a rendered receipt — text extraction cannot see it, which is why no earlier test did):
 *   1. A font registered here does not get mPDF's `useOTL` switch by itself (only its bundled fonts carry it). Without it a
 *      pre-base vowel sign stays AFTER its consonant — পরিশোধ printed as পরশিোধ — and conjuncts are not formed.
 *   2. With the switch on, mPDF 8.3.1's OpenType reader cannot read the current Noto Sans Bengali (3.x): its GDEF 1.2
 *      table is read at the wrong offset, and it rejects the font's newer lookup formats ("GPOS Lookup Type 5, Format 3
 *      not supported"). The 2.001 release of the same typeface (GDEF 1.0, classic lookups) reads and shapes correctly,
 *      so that is the Bengali font here: resources/fonts/NotoSansBengali-Shaping-*.ttf.
 *   3. The 2.001 release has no Latin letters. They come from the 3.x files (which do, and which the browser view uses
 *      too) through mPDF's glyph substitution (`useSubstitutions` + `backupSubsFont`) — so a receipt shows
 *      "Provatferi", "PLCC-RCT-2026-000001" or a member's English name in Noto Sans, next to correctly shaped Bengali.
 *   4. mPDF parses the font's shaping tables on first use and caches them (per release: vendor/mpdf/mpdf/tmp); the very
 *      first document after a cache is built can still come out unshaped. warmShapingCache() makes that first
 *      document a throw-away probe, once, under a lock.
 *   5. The price: with shaped glyphs the PDF's text layer no longer matches the Unicode text for Bengali (conjunct glyphs
 *      have no Unicode value), so copying Bengali out of a PDF gives fragments. Latin text, numbers and amounts extract fine.
 * mPDF's own autoScriptToLang/autoLangToFont auto-detection was found to silently
 * fall back to a core font with NO Bengali glyphs for bold text specifically
 * (tofu boxes) once a custom font is registered — both are left OFF here,
 * so every weight routes through the explicitly-registered fonts. Do not
 * re-enable them without re-running the conjunct/bold verification this
 * class's tests do.
 *
 * If shaping ever throws (a font file replaced by one mPDF cannot read, an unwritable cache), the document is still
 * produced — unshaped, from the 3.x files as before — and the reason is logged: an official receipt is never withheld
 * over typography. Look at a rendered page after any change here: scripts/pdf-rasterize.mjs.
 */
class RecruitmentPdfService
{
    private const FONT_DIR = 'fonts';

    /** Bump when a Bengali shaping font file changes, so a new probe runs against the new tables. */
    private const SHAPING_CACHE_VERSION = 1;

    /** Set when shaping threw in this process: the rest of it renders unshaped without trying again. */
    private static bool $shapingFailed = false;

    /**
     * The one PDF engine of the admin: also renders the official payment receipts (Membership task 5) — same fonts,
     * same settings, no second engine. $title is the document's title in the PDF's metadata (a viewer's tab/window).
     */
    public function render(string $html, string $title = 'Provatferi ERP'): string
    {
        if (! self::$shapingFailed) {
            $shaped = $this->config(true);

            try {
                $this->warmShapingCache($shaped);

                return $this->write($shaped, $html, $title);
            } catch (Throwable $e) {
                self::$shapingFailed = true; // not retried for the rest of this process
                Log::warning('PDF: Bengali shaping failed — the document was produced without it. '.$e::class.': '.$e->getMessage());
            }
        }

        return $this->write($this->config(false), $html, $title);
    }

    /** @param array<string, mixed> $config */
    private function write(array $config, string $html, string $title): string
    {
        $mpdf = new Mpdf($config);
        $mpdf->SetTitle($title);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * mPDF's configuration. $shaped = the shaping fonts with OpenType layout and Latin substitution (see the class
     * comment); otherwise the 3.x files alone, as this service ran before — the fallback.
     *
     * @return array<string, mixed>
     */
    private function config(bool $shaped): array
    {
        $fontDir = resource_path(self::FONT_DIR);

        $defaultConfig = (new ConfigVariables())->getDefaults();
        $defaultFontConfig = (new FontVariables())->getDefaults();

        $config = [
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => 15,
            'margin_bottom' => 15,
            'margin_left' => 15,
            'margin_right' => 15,
            'tempDir' => $defaultConfig['tempDir'],
            'fontDir' => array_merge($defaultConfig['fontDir'], [$fontDir]),
            'default_font' => 'notosansbengali',
            // Deliberately off — see class docblock.
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
        ];

        $config['fontdata'] = $defaultFontConfig['fontdata'] + ($shaped ? [
            'notosansbengali' => ['R' => 'NotoSansBengali-Shaping-Regular.ttf', 'B' => 'NotoSansBengali-Shaping-Bold.ttf', 'useOTL' => 0xFF],
            'notosanslatin' => ['R' => 'NotoSansBengali-Regular.ttf', 'B' => 'NotoSansBengali-Bold.ttf'],
        ] : [
            'notosansbengali' => ['R' => 'NotoSansBengali-Regular.ttf', 'B' => 'NotoSansBengali-Bold.ttf'],
        ]);

        if ($shaped) {
            $config['useSubstitutions'] = true;
            $config['backupSubsFont'] = ['notosanslatin'];
        }

        return $config;
    }

    /**
     * Once per release (mPDF's font cache lives under vendor/, which a release carries with it): make mPDF parse and
     * cache the Bengali font's shaping tables with a throw-away document, under a lock, so that no real document is
     * ever the one that builds them. A marker file records that it was done; a document that runs while another
     * process is still building waits for it.
     *
     * @param  array<string, mixed>  $config
     */
    private function warmShapingCache(array $config): void
    {
        $base = rtrim((string) $config['tempDir'], '/\\').'/mpdf';
        $marker = $base.'/.shaping-ready-v'.self::SHAPING_CACHE_VERSION;
        if (is_file($marker)) {
            return;
        }
        if (! is_dir($base) && ! @mkdir($base, 0775, true) && ! is_dir($base)) {
            return; // nowhere to coordinate: mPDF cannot cache either, and will say so itself
        }

        $lock = @fopen($base.'/.shaping.lock', 'c');
        if ($lock === false) {
            return;
        }
        try {
            flock($lock, LOCK_EX);
            if (! is_file($marker)) {
                $probe = new Mpdf($config);
                $probe->WriteHTML('<p>পরিশোধ ক্ষ ত্য Abc ০১২</p><p><b>পরিশোধ ক্ষ ত্য Abc ০১২</b></p>');
                $probe->Output('', Destination::STRING_RETURN);
                file_put_contents($marker, gmdate('c'));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
