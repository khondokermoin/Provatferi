<?php

namespace App\Services;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders application-document HTML to a PDF that actually forms Bengali
 * conjuncts correctly. This is not a detail — it's the whole reason mPDF was
 * chosen over dompdf here: dompdf has no OpenType GSUB/GPOS shaping engine at
 * all, so it CANNOT join Bengali consonant clusters (ক্ষ, জ্ঞ, স্ব, ত্ত্ব) —
 * it draws each codepoint's glyph independently regardless of font. mPDF
 * ships its own complex-script shaping engine, verified empirically against
 * real conjuncts and reph/matra-reordering cases before this class existed.
 *
 * One configuration detail matters as much as the library choice: mPDF's
 * own autoScriptToLang/autoLangToFont auto-detection was found to silently
 * fall back to a core font with NO Bengali glyphs for bold text specifically
 * (tofu boxes) once a custom font is registered — both are left OFF here,
 * so every weight routes through the one explicitly-registered font. Do not
 * re-enable them without re-running the conjunct/bold verification this
 * class's tests do.
 */
class RecruitmentPdfService
{
    private const FONT_DIR = 'fonts';

    public function render(string $html): string
    {
        $fontDir = resource_path(self::FONT_DIR);

        $defaultConfig = (new ConfigVariables())->getDefaults();
        $defaultFontConfig = (new FontVariables())->getDefaults();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => 15,
            'margin_bottom' => 15,
            'margin_left' => 15,
            'margin_right' => 15,
            'fontDir' => array_merge($defaultConfig['fontDir'], [$fontDir]),
            'fontdata' => $defaultFontConfig['fontdata'] + [
                'notosansbengali' => [
                    'R' => 'NotoSansBengali-Regular.ttf',
                    'B' => 'NotoSansBengali-Bold.ttf',
                ],
            ],
            'default_font' => 'notosansbengali',
            // Deliberately off — see class docblock.
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
        ]);

        $mpdf->SetTitle('Provatferi ERP');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
