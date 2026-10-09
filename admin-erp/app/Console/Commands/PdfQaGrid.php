<?php

namespace App\Console\Commands;

use App\Services\Pdf\PdfRenderer;
use App\Services\Pdf\PdfShapingCheck;
use Illuminate\Console\Command;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Writes the input of the visual shaping QA (deploy/qa/pdf-shaping-qa.mjs): one Bengali word per PAGE of a small PDF drawn by the
 * PRODUCT engine, and the same pages as HTML for Chrome, which shapes with HarfBuzz — the reference. The Node script prints the
 * HTML to PDF, rasterises both with PDFium and compares them word by word. This command only produces the two inputs.
 */
class PdfQaGrid extends Command
{
    protected $signature = 'pdf:qa-grid
        {out : Directory to write into (created if missing)}
        {--set=fixture : fixture = the shaping fixture; lang = every Bengali word of lang/bn/*.php too (what the product can print)}
        {--weight=regular : regular | bold}
        {--size=16 : Font size in pt}
        {--batch=700 : Words per PDF}
        {--unshaped : NEGATIVE CONTROL — the same pages drawn WITHOUT OpenType layout, in a cache of their own; the QA must flag them}';

    protected $description = 'Write the pages the visual Bengali shaping QA compares against a HarfBuzz rendering (deploy/qa/pdf-shaping-qa.mjs)';

    private const PAGE_WIDTH_MM = 62;

    private const PAGE_HEIGHT_MM = 14;

    public function handle(PdfRenderer $renderer, PdfShapingCheck $check): int
    {
        $weight = (string) $this->option('weight');
        $set = (string) $this->option('set');
        if (! in_array($weight, ['regular', 'bold'], true) || ! in_array($set, ['fixture', 'lang'], true)) {
            $this->error('--weight is regular|bold and --set is fixture|lang.');

            return self::FAILURE;
        }
        $out = rtrim((string) $this->argument('out'), '/\\');
        if (! is_dir($out) && ! mkdir($out, 0775, true) && ! is_dir($out)) {
            $this->error("Cannot create {$out}");

            return self::FAILURE;
        }

        $words = $set === 'lang' ? $check->langWords() : $check->words();
        $size = (float) $this->option('size');
        $fontFile = str_replace('\\', '/', resource_path('fonts/NotoSansBengali-Shaping-'.($weight === 'bold' ? 'Bold' : 'Regular').'.ttf'));
        $style = 'white-space:nowrap;font-size:'.$size.'pt;line-height:'.(self::PAGE_HEIGHT_MM - 2).'mm;height:'.(self::PAGE_HEIGHT_MM - 2).'mm;padding:0 0 0 1mm;margin:0;color:#000;'.($weight === 'bold' ? 'font-weight:bold;' : '');

        foreach (array_chunk($words, max(1, (int) $this->option('batch'))) as $n => $chunk) {
            $name = "{$set}-{$weight}-{$n}";
            $page = ['format' => [self::PAGE_WIDTH_MM, self::PAGE_HEIGHT_MM], 'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0, 'margin_header' => 0, 'margin_footer' => 0];
            if ($this->option('unshaped')) {
                // Same fonts, no `useOTL`, and a temp directory of its own so the product's font cache is never touched.
                $config = $renderer->config($page + ['tempDir' => sys_get_temp_dir().'/pdf-qa-unshaped']);
                unset($config['fontdata'][PdfRenderer::FONT_BENGALI]['useOTL']);
                $mpdf = new Mpdf($config);
            } else {
                $mpdf = $renderer->newMpdf($page);
            }
            $html = '<style>body{font-family:'.PdfRenderer::FONT_BENGALI.';color:#000}</style>';
            $chrome = '<!doctype html><html lang="bn"><head><meta charset="utf-8"><style>@page{size:'.self::PAGE_WIDTH_MM.'mm '.self::PAGE_HEIGHT_MM.'mm;margin:0}'
                .'@font-face{font-family:Bn;src:url("file:///'.$fontFile.'");font-weight:'.($weight === 'bold' ? 700 : 400).'}html,body{margin:0;padding:0}'
                .'.w{font-family:Bn;'.$style.'page-break-after:always;overflow:hidden;box-sizing:border-box;width:'.self::PAGE_WIDTH_MM.'mm}.w:last-child{page-break-after:auto}</style></head><body>';
            foreach ($chunk as $i => $word) {
                $escaped = htmlspecialchars($word);
                $html .= ($i > 0 ? '<pagebreak />' : '').'<div style="'.$style.'">'.$escaped.'</div>';
                $chrome .= '<div class="w">'.$escaped.'</div>';
            }
            $mpdf->WriteHTML($html);
            file_put_contents("{$out}/{$name}.mpdf.pdf", $mpdf->Output('', Destination::STRING_RETURN));
            file_put_contents("{$out}/{$name}.chrome.html", $chrome.'</body></html>');
            file_put_contents("{$out}/{$name}.layout.json", json_encode(['mode' => 'page', 'size' => $size, 'cells' => array_map(fn (string $w) => ['word' => $w], $chunk)], JSON_UNESCAPED_UNICODE));
            $this->line("{$name}: ".count($chunk).' words');
        }

        return self::SUCCESS;
    }
}
