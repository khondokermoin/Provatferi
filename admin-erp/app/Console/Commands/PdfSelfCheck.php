<?php

namespace App\Console\Commands;

use App\Services\Pdf\PdfInspector;
use App\Services\Pdf\PdfRenderer;
use App\Services\Pdf\PdfShapingCheck;
use Illuminate\Console\Command;
use Throwable;

/**
 * Proves, on THIS install, that the PDF engine can produce correctly shaped Bengali: the font files are the recorded ones, the
 * mPDF font cache is complete, and every word of the shaping fixture is drawn the way it was when somebody last looked at the
 * rendered page (docs/PDF_BENGALI_STANDARD.md). Run it after a deploy and before trusting a receipt; the exit code is the answer.
 */
class PdfSelfCheck extends Command
{
    protected $signature = 'pdf:self-check
        {--json : Print the result as JSON}
        {--write-golden : Developers only, after running deploy/qa/pdf-shaping-qa.mjs and LOOKING at the pages: record the current glyph counts as the golden file}';

    protected $description = 'Check the Bengali PDF engine on this install: font files, font cache, shaping of the fixture, embedded fonts';

    public function handle(PdfRenderer $renderer, PdfShapingCheck $check): int
    {
        if ($this->option('write-golden')) {
            $this->info('Wrote '.$check->writeGolden());

            return self::SUCCESS;
        }

        $report = ['fonts' => [], 'cache' => [], 'shaping' => [], 'embedding' => []];
        try {
            $report['fonts'] = $check->fontProblems();
            // Rendering is what builds (and verifies) the font cache, so the cache is read afterwards.
            $report['shaping'] = $check->mismatches();
            $report['cache'] = $renderer->cacheStatus();

            $sample = $renderer->render('<p>পরিশোধ ক্ষ ত্য অক্টোবর <b>সাংস্কৃতিক কেন্দ্র</b> Abc ০১২ ৳৬০০ · — ✓ ✗</p>', 'pdf-self-check');
            $analysis = PdfInspector::analyse($sample);
            $embedded = array_values(array_unique(array_map(fn (array $f) => $f['base'], array_filter($analysis['fonts'], fn (array $f) => $f['embedded']))));
            $unembedded = PdfInspector::usedUnembeddedFonts($sample);
            if ($unembedded !== []) {
                $report['embedding'][] = 'text is drawn with fonts that are not embedded: '.implode(', ', $unembedded);
            }
            if (count($embedded) !== count(array_filter($analysis['fonts'], fn (array $f) => $f['embedded']))) {
                $report['embedding'][] = 'two embedded font programs carry the same name: '.implode(', ', $embedded);
            }
            $report['embedded_fonts'] = $embedded;
        } catch (Throwable $e) {
            $report['exception'] = $e::class.': '.$e->getMessage();
        }

        $ok = $report['fonts'] === [] && $report['shaping'] === [] && $report['embedding'] === [] && ($report['cache']['ready'] ?? false) && ! isset($report['exception']);
        $report['ok'] = $ok;

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->line('Font files:      '.($report['fonts'] === [] ? 'ok' : 'PROBLEMS'));
            foreach ($report['fonts'] as $problem) {
                $this->error("  - {$problem}");
            }
            $this->line('Font cache:      '.(($report['cache']['ready'] ?? false) ? 'ready' : 'NOT READY'));
            $this->line('Shaping:         '.($report['shaping'] === [] ? 'every fixture word as recorded' : count($report['shaping']).' DIFFERENCES'));
            foreach (array_slice($report['shaping'], 0, 15) as $problem) {
                $this->error("  - {$problem}");
            }
            $this->line('Embedded fonts:  '.($report['embedding'] === [] ? implode(', ', $report['embedded_fonts'] ?? []) : 'PROBLEMS'));
            foreach ($report['embedding'] as $problem) {
                $this->error("  - {$problem}");
            }
            if (isset($report['exception'])) {
                $this->error($report['exception']);
            }
            $this->line($ok ? 'PDF engine: OK' : 'PDF engine: FAILED');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
