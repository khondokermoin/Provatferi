<?php

namespace Tests\Feature\Pdf;

use App\Services\Pdf\PdfEngineException;
use App\Services\Pdf\PdfInspector;
use App\Services\Pdf\PdfRenderer;
use App\Services\Pdf\PdfShapingCheck;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Tests\TestCase;

/**
 * The Bengali PDF engine (docs/PDF_BENGALI_STANDARD.md).
 *
 * What these tests can and cannot prove: they hold the engine to the glyph signature recorded when somebody LOOKED at the
 * rendered pages (resources/pdf-qa/bengali-shaping-glyphs.json), they check the structure of the PDF (fonts embedded, named
 * once, nothing left to the viewer) and they reproduce the failure that made Bengali "right, then wrong, then right again".
 * They do NOT prove that the shaping is correct — only the pixel comparison against a HarfBuzz rendering does that
 * (deploy/qa/pdf-shaping-qa.mjs, run in CI and before every change to the fonts or the engine). Extracted text proves nothing:
 * for months the PDFs came out unshaped and every test that read their text called that fine.
 */
class PdfRendererTest extends TestCase
{
    /** Words that need reordering, ligatures and a split vowel sign: unshaped they draw one glyph per code point. */
    private const SAMPLE = '<p style="font-size: 20pt">ক্ষ অক্টোবর পরিশোধ অংশের স্বেচ্ছাসেবী</p>';

    /** A warmed cache directory, built once and copied into every test's private one (a cold start costs seconds). */
    private static ?string $template = null;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$template === null) {
            self::$template = $this->newTempPath('template');
            (new PdfRenderer(self::$template))->render('<p>পরিশোধ</p>', 'warm');
        }
        $this->tempDir = $this->newTempPath('case');
        $this->copyDirectory(self::$template, $this->tempDir);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$template !== null) {
            self::removeDirectory(self::$template);
            self::$template = null;
        }
        parent::tearDownAfterClass();
    }

    /* ================================================================ what is in the file */

    public function test_every_font_a_document_draws_with_is_embedded_and_each_program_has_its_own_name(): void
    {
        $pdf = (new PdfRenderer($this->tempDir))->render(
            '<p>পরিশোধ ক্ষ <b>সাংস্কৃতিক কেন্দ্র</b> Provatferi <b>PLCC-RCT-2026-000001</b> ০১২ ৳৬০০ · — – ‘ ’ ✓ ✗</p>',
            'fonts',
        );

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame([], PdfInspector::usedUnembeddedFonts($pdf), 'a core font is drawn from whatever the viewer has (Helvetica for ·, ZapfDingbats for ✓)');

        $embedded = array_map(fn (array $font) => $font['base'], array_filter(PdfInspector::analyse($pdf)['fonts'], fn (array $font) => $font['embedded']));
        $this->assertSame(count($embedded), count(array_unique($embedded)), 'two different font programs carry one name: '.implode(', ', $embedded));
        foreach (['NotoSansBengaliShaping-Regular', 'NotoSansBengaliShaping-Bold', 'NotoSansBengali-Regular', 'NotoSansBengali-Bold', 'DejaVuSans'] as $program) {
            $this->assertContains('MPDFAA+'.$program, $embedded, "{$program} is embedded (as a subset)");
        }
    }

    public function test_images_go_in_as_variables_so_the_html_stays_small(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $pdf = (new PdfRenderer($this->tempDir))->render('<p>পরিশোধ <img src="var:mark" style="height: 10mm" alt=""></p>', 'image', ['mark' => $png]);

        $this->assertStringContainsString('/Subtype /Image', $pdf);
    }

    public function test_html_too_large_for_mpdf_is_refused_with_an_explanation_instead_of_a_500_from_inside_mpdf(): void
    {
        $this->expectException(PdfEngineException::class);
        $this->expectExceptionMessage('Do not embed images as base64');

        (new PdfRenderer($this->tempDir))->render(str_repeat('<p>x</p>', 200_000), 'too large');
    }

    /* ================================================================ how it is shaped */

    public function test_every_word_of_the_fixture_is_drawn_the_way_it_was_when_last_verified_by_eye(): void
    {
        $this->assertSame([], (new PdfShapingCheck(new PdfRenderer($this->tempDir)))->mismatches());
    }

    public function test_the_glyph_signature_can_tell_shaped_bengali_from_unshaped(): void
    {
        $shaped = $this->glyphs((new PdfRenderer($this->tempDir))->render(self::SAMPLE, 'shaped'));

        // The same font without OpenType layout, in a cache of its own: what an unshaped document is.
        $renderer = new PdfRenderer($this->newTempPath('unshaped'));
        $config = $renderer->config();
        unset($config['fontdata'][PdfRenderer::FONT_BENGALI]['useOTL']);
        $mpdf = new Mpdf($config);
        $mpdf->WriteHTML(self::SAMPLE);
        $unshaped = $this->glyphs($mpdf->Output('', Destination::STRING_RETURN));
        self::removeDirectory($config['tempDir']);

        $this->assertLessThan(array_sum($unshaped), array_sum($shaped), 'shaped Bengali draws fewer glyphs than it has code points (a conjunct is one glyph)');
        $this->assertNotSame($shaped, $unshaped);
    }

    /* ================================================================ the failure that was in production */

    /**
     * mPDF memoises every cache file it reads and never notices when it rewrites one. The old service entered an unshaped
     * configuration — under the SAME font key — whenever anything failed (an oversized photo was enough): the next shaped
     * document in any process was then built from the stale copy and came out unshaped. This is that event, and the engine
     * must still shape the next document.
     */
    public function test_a_failed_or_unshaped_document_cannot_change_how_the_next_one_is_shaped(): void
    {
        $renderer = new PdfRenderer($this->tempDir);
        $expected = $this->glyphs($renderer->render(self::SAMPLE, 'before'));

        $this->poison($this->tempDir);
        $this->assertFalse($renderer->cacheStatus()['ready'], 'the cache no longer claims to be ready after another configuration rewrote it');

        $this->assertSame($expected, $this->glyphs($renderer->render(self::SAMPLE, 'after')), 'the document after the poisoning is shaped exactly as before');
        $this->assertTrue($renderer->cacheStatus()['ready'], 'and the cache was repaired and verified');
    }

    public function test_the_flaw_the_guard_exists_for_is_real(): void
    {
        $renderer = new PdfRenderer($this->tempDir);
        $shaped = $this->glyphs($renderer->render(self::SAMPLE, 'before'));

        $this->poison($this->tempDir);
        // A plain mPDF document straight after it — what every document in the old service was — is built unshaped.
        $mpdf = new Mpdf($renderer->config());
        $mpdf->WriteHTML(self::SAMPLE);
        $plain = $this->glyphs($mpdf->Output('', Destination::STRING_RETURN));

        $this->assertNotSame($shaped, $plain, 'mPDF no longer shows the stale-cache flaw: PdfFontCache may be simplified (see its class comment)');
    }

    public function test_a_damaged_cache_is_rebuilt_not_trusted(): void
    {
        $renderer = new PdfRenderer($this->tempDir);
        $expected = $this->glyphs($renderer->render(self::SAMPLE, 'before'));

        // A truncated file — a crash in the middle of a write, a cleaner that took half of them.
        file_put_contents($renderer->cacheStatus()['directory'].'/'.PdfRenderer::FONT_BENGALI.'.GSUBGPOStables.dat', '');
        $this->assertFalse($renderer->cacheStatus()['ready']);
        $this->assertContains(PdfRenderer::FONT_BENGALI.'.GSUBGPOStables.dat', $renderer->cacheStatus()['missing']);

        $this->assertSame($expected, $this->glyphs($renderer->render(self::SAMPLE, 'after')));
        $this->assertSame([], $renderer->cacheStatus()['missing']);
    }

    public function test_a_healthy_cache_is_left_alone(): void
    {
        $renderer = new PdfRenderer($this->tempDir);
        $renderer->render(self::SAMPLE, 'warm');
        $files = glob($this->tempDir.'/mpdf/ttfontdata/'.PdfRenderer::FONT_BENGALI.'*') ?: [];
        $this->assertNotEmpty($files);
        $mtimes = array_map(fn (string $f) => filemtime($f).':'.filesize($f), $files);
        clearstatcache();

        $renderer->render(self::SAMPLE, 'again');
        clearstatcache();

        $this->assertSame($mtimes, array_map(fn (string $f) => filemtime($f).':'.filesize($f), $files), 'no document rewrites a cache that is already right');
    }

    public function test_no_unshaped_document_is_ever_handed_out(): void
    {
        $blocker = tempnam(sys_get_temp_dir(), 'pdf-blocker-'); // a FILE where the temp directory has to go
        try {
            $renderer = new PdfRenderer($blocker.'/cache');
            try {
                $renderer->render(self::SAMPLE, 'cannot');
                $this->fail('a document was produced although the Bengali fonts could not be prepared');
            } catch (PdfEngineException $e) {
                $this->assertStringContainsString('not', strtolower($e->getMessage()));
            }
        } finally {
            @unlink($blocker);
        }
    }

    /* ================================================================ helpers */

    /** @return list<int> glyphs the Bengali font draws, per page of text */
    private function glyphs(string $pdf): array
    {
        return PdfInspector::glyphsPerPage($pdf, 'Shaping');
    }

    /** What the removed unshaped fallback did: the same font keys, registered with the 3.x files and no OpenType layout. */
    private function poison(string $tempDir): void
    {
        $config = (new PdfRenderer($tempDir))->config();
        $config['fontdata'][PdfRenderer::FONT_BENGALI] = ['R' => 'NotoSansBengali-Regular.ttf', 'B' => 'NotoSansBengali-Bold.ttf'];
        unset($config['useSubstitutions'], $config['backupSubsFont']);
        $mpdf = new Mpdf($config);
        $mpdf->WriteHTML('<p>পরিশোধ</p><p><b>পরিশোধ</b></p>');
        $mpdf->Output('', Destination::STRING_RETURN);
    }

    private function newTempPath(string $label): string
    {
        return sys_get_temp_dir().'/provatferi-pdf-'.$label.'-'.bin2hex(random_bytes(4));
    }

    private function copyDirectory(string $from, string $to): void
    {
        @mkdir($to, 0775, true);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $target = $to.'/'.substr($item->getPathname(), strlen($from) + 1);
            $item->isDir() ? @mkdir($target, 0775, true) : copy($item->getPathname(), $target);
        }
    }

    private static function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
