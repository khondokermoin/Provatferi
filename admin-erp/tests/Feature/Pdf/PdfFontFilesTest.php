<?php

namespace Tests\Feature\Pdf;

use App\Services\Pdf\PdfFontAudit;
use App\Services\Pdf\PdfRenderer;
use App\Services\Pdf\PdfShapingCheck;
use Tests\TestCase;

/**
 * The font files themselves (docs/PDF_BENGALI_STANDARD.md): the right release, every table the shaping needs present and
 * unmodified, Regular and Bold mapped to the right files, every character a document can print covered, and no two font
 * programs sharing a name inside one PDF. FONTS.json records the truth (deploy/fonts-manifest.mjs).
 */
class PdfFontFilesTest extends TestCase
{
    private function check(): PdfShapingCheck
    {
        return new PdfShapingCheck(new PdfRenderer());
    }

    public function test_the_font_files_are_the_recorded_ones_and_can_do_their_job(): void
    {
        $this->assertSame([], $this->check()->fontProblems());
    }

    public function test_the_manifest_covers_every_font_the_engine_embeds_except_mpdfs_own_symbols(): void
    {
        $renderer = new PdfRenderer();
        $manifest = $this->check()->manifest();

        foreach ($renderer->fontFiles() as $key => $path) {
            if (str_starts_with($key, PdfRenderer::FONT_SYMBOLS)) {
                continue; // DejaVu Sans comes with mPDF (vendor/mpdf/mpdf/ttfonts)
            }
            $this->assertArrayHasKey(basename($path), $manifest, "{$key} ({$path}) is not recorded in resources/fonts/FONTS.json");
        }
    }

    public function test_bengali_comes_from_2_001_where_mpdf_can_read_it_and_the_latin_from_3_011(): void
    {
        $manifest = $this->check()->manifest();

        foreach (['NotoSansBengali-Shaping-Regular.ttf', 'NotoSansBengali-Shaping-Bold.ttf'] as $file) {
            $this->assertSame('Version 2.001', $manifest[$file]['version']);
            $audit = PdfFontAudit::inspect(resource_path('fonts/'.$file));
            $this->assertSame('1.0', $audit['layout']['GDEF']['version'], 'mPDF 8.3.1 reads GDEF 1.0; 1.2 is read at the wrong offset and its newer GPOS lookup formats are rejected');
            $this->assertContains('bng2', $audit['layout']['GSUB']['scripts']);
            $this->assertContains('bng2', $audit['layout']['GPOS']['scripts']);
            $this->assertGreaterThan(50, $audit['layout']['GSUB']['lookups']);
        }
        foreach (['NotoSansBengali-Regular.ttf', 'NotoSansBengali-Bold.ttf'] as $file) {
            $this->assertSame('Version 3.011', $manifest[$file]['version']);
        }
    }

    public function test_regular_and_bold_are_mapped_to_the_right_files(): void
    {
        $files = (new PdfRenderer())->fontFiles();

        $this->assertSame(400, PdfFontAudit::inspect($files[PdfRenderer::FONT_BENGALI])['weight']);
        $this->assertSame(700, PdfFontAudit::inspect($files[PdfRenderer::FONT_BENGALI.'B'])['weight']);
        $this->assertSame(400, PdfFontAudit::inspect($files[PdfRenderer::FONT_LATIN])['weight']);
        $this->assertSame(700, PdfFontAudit::inspect($files[PdfRenderer::FONT_LATIN.'B'])['weight']);
    }

    public function test_no_font_file_is_a_subset_of_what_the_documents_need(): void
    {
        // A subsetting tool run over these files with the default settings drops glyphs or layout features. Both releases carry
        // the full Bengali block: this fails if one is ever replaced by a subset.
        foreach (['NotoSansBengali-Shaping-Regular.ttf', 'NotoSansBengali-Regular.ttf'] as $file) {
            $audit = PdfFontAudit::inspect(resource_path('fonts/'.$file));
            $this->assertGreaterThanOrEqual(650, $audit['glyphs'], "{$file} has only {$audit['glyphs']} glyphs");
            $this->assertSame(1000, $audit['unitsPerEm']);
        }
    }
}
