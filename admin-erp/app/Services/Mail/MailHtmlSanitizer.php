<?php

namespace App\Services\Mail;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Turns hostile e-mail HTML into a safe subset (docs/MAIL_CENTER.md, "Reading mail safely"), with HTML Purifier — an
 * allow-list sanitizer: what is not explicitly allowed is dropped, rather than what is known to be bad being removed.
 *
 * Gone, always: script, style and link elements, iframe, frame, object, embed, applet, form and every form control, meta,
 * base, every on* event attribute, ids (no clobbering of page globals), javascript:/vbscript:/data:text URIs, and every
 * CSS property that can load a resource (url(), background, list-style-image) or lay content over the page (position,
 * z-index, top/left/…, opacity, filter). Links keep only http, https and mailto and open in a new tab without a referrer.
 *
 * This is the first of three layers: MailBodyRenderer then decides which images may load, and the result is shown in a
 * sandboxed frame that cannot run scripts or submit forms, under a Content-Security-Policy that forbids scripts outright.
 */
final class MailHtmlSanitizer
{
    /** CSS properties kept in style attributes. None of them can fetch anything or cover the page. */
    public const ALLOWED_CSS = [
        'color', 'background-color', 'direction',
        'font', 'font-family', 'font-size', 'font-style', 'font-variant', 'font-weight',
        'letter-spacing', 'line-height', 'word-spacing', 'white-space',
        'text-align', 'text-decoration', 'text-decoration-color', 'text-decoration-line', 'text-decoration-style', 'text-indent', 'text-transform', 'vertical-align',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-color', 'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
        'border-style', 'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style',
        'border-width', 'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
        'border-radius', 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-left-radius', 'border-bottom-right-radius',
        'border-collapse', 'border-spacing', 'caption-side', 'table-layout',
        'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height',
        'float', 'clear', 'display', 'visibility', 'overflow',
        'list-style-type', 'list-style-position',
    ];

    private ?HTMLPurifier $purifier = null;

    public function sanitize(string $html): string
    {
        return $this->purifier()->purify($html);
    }

    private function purifier(): HTMLPurifier
    {
        if ($this->purifier !== null) {
            return $this->purifier;
        }
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        // Transitional: e-mail is written with tables and their old presentational attributes (width, align, bgcolor).
        $config->set('HTML.Doctype', 'XHTML 1.0 Transitional');
        $config->set('HTML.SafeIframe', false);
        $config->set('HTML.SafeObject', false);
        $config->set('HTML.SafeEmbed', false);
        $config->set('HTML.Trusted', false);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.TargetNoreferrer', true);
        $config->set('HTML.Nofollow', true);
        $config->set('Attr.EnableID', false);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'data' => true]);
        // "Tricky" adds display/visibility/overflow, "Proprietary" the border-radius family. Both only make properties
        // AVAILABLE: what is kept is exactly ALLOWED_CSS (so opacity, filter and the rest of those groups stay out).
        $config->set('CSS.AllowTricky', true);
        $config->set('CSS.Proprietary', true);
        $config->set('CSS.AllowedProperties', array_fill_keys(self::ALLOWED_CSS, true));
        $config->set('CSS.MaxImgLength', null);
        $config->set('Core.RemoveProcessingInstructions', true);
        $config->set('AutoFormat.RemoveEmpty', false);

        $cache = storage_path('framework/cache/htmlpurifier');
        if (! is_dir($cache)) {
            @mkdir($cache, 0755, true);
        }
        if (is_dir($cache) && is_writable($cache)) {
            $config->set('Cache.SerializerPath', $cache);
        } else {
            $config->set('Cache.DefinitionImpl', null);
        }

        return $this->purifier = new HTMLPurifier($config);
    }
}
