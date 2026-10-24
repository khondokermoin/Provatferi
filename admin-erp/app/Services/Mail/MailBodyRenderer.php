<?php

namespace App\Services\Mail;

use App\Services\Mail\Data\MailMessageText;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Prepares a message body for the page (docs/MAIL_CENTER.md, "Reading mail safely").
 *
 * HTML messages: (1) cid: pictures are pointed at the ERP's own attachment route for this message; (2) MailHtmlSanitizer
 * reduces the HTML to an allow-listed subset; (3) a second pass over the sanitized tree keeps only images the reader may
 * load — the message's own inline pictures and validated data: images always, REMOTE images only when the reader asked for
 * them (they would tell the sender that, when and from where the message was read) — strips any background attribute, and
 * keeps links only for http, https and mailto; (4) the result is wrapped in a document whose Content-Security-Policy
 * forbids scripts, frames, forms and every fetch but those images, and which sends no referrer. The page shows that
 * document in an iframe sandbox without scripts, forms or top-level navigation.
 *
 * Plain-text messages are escaped character by character; only http(s) addresses become links.
 */
final class MailBodyRenderer
{
    /** Larger HTML parts are not parsed: the text part is shown instead. */
    public const MAX_HTML_BYTES = 2 * 1024 * 1024;

    /** A 1×1 transparent GIF: what a held-back remote image shows, at its own width and height. */
    private const BLANK_IMAGE = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(private readonly MailHtmlSanitizer $sanitizer) {}

    /**
     * @param  array<string, string>  $inlineImages  content id (lower-case, without < >) => the ERP's URL of that inline picture
     */
    public function render(MailMessageText $body, bool $allowRemoteImages, array $inlineImages = []): RenderedMailBody
    {
        $html = trim($body->html);
        $tooLarge = strlen($html) > self::MAX_HTML_BYTES;
        if ($html !== '' && ! $tooLarge) {
            $clean = $this->sanitizer->sanitize($this->pointCidAtAttachments($html, $inlineImages));
            [$filtered, $remote, $blocked] = $this->filterResources($clean, $allowRemoteImages, array_values($inlineImages));

            return new RenderedMailBody($this->document($filtered, $allowRemoteImages), null, $remote, $blocked);
        }
        $text = $body->text !== '' ? $body->text : html_entity_decode(strip_tags($body->html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return new RenderedMailBody(null, self::plainTextHtml($text), htmlTooLarge: $tooLarge);
    }

    /** Escapes every character of $text and turns http(s) addresses into links that open in a new tab without a referrer. */
    public static function plainTextHtml(string $text): string
    {
        $parts = preg_split('~(\bhttps?://[^\s<>"\']+)~iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $out = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $out .= e($part);

                continue;
            }
            // Punctuation that usually ends the sentence, not the address.
            $trail = '';
            while ($part !== '' && str_contains('.,;:!?)]\'"', substr($part, -1))) {
                $trail = substr($part, -1).$trail;
                $part = substr($part, 0, -1);
            }
            $out .= '<a href="'.e($part).'" target="_blank" rel="noopener noreferrer nofollow">'.e($part).'</a>'.e($trail);
        }

        return $out;
    }

    /** @param array<string, string> $inlineImages */
    private function pointCidAtAttachments(string $html, array $inlineImages): string
    {
        if ($inlineImages === []) {
            return $html;
        }

        return (string) preg_replace_callback('/(["\'\s=])cid:([^"\'\s>]+)/i', function (array $m) use ($inlineImages) {
            $id = strtolower(trim(rawurldecode($m[2]), '<>'));

            return isset($inlineImages[$id]) ? $m[1].$inlineImages[$id] : $m[0];
        }, $html);
    }

    /**
     * @param  list<string>  $ownUrls
     * @return array{0: string, 1: int, 2: int} the HTML, remote images seen, remote images held back
     */
    private function filterResources(string $html, bool $allowRemote, array $ownUrls): array
    {
        if (trim($html) === '') {
            return ['', 0, 0];
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><div id="mc-root">'.$html.'</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($doc);
        $remote = 0;
        $blocked = 0;

        foreach (iterator_to_array($xpath->query('//img')) as $img) {
            /** @var DOMElement $img */
            $src = trim($img->getAttribute('src'));
            $img->removeAttribute('srcset');
            if ($src !== '' && in_array($src, $ownUrls, true)) {
                continue;
            }
            if (preg_match('~^data:image/(png|jpe?g|gif|webp);base64,~i', $src) === 1) {
                continue;
            }
            if (preg_match('~^https?://~i', $src) === 1) {
                $remote++;
                if ($allowRemote) {
                    $img->setAttribute('referrerpolicy', 'no-referrer');
                    $img->setAttribute('loading', 'lazy');

                    continue;
                }
                $blocked++;
                $img->setAttribute('src', self::BLANK_IMAGE);
                $img->setAttribute('class', trim($img->getAttribute('class').' mc-blocked-image'));

                continue;
            }
            $img->parentNode?->removeChild($img);
        }
        foreach (iterator_to_array($xpath->query('//*[@background]')) as $element) {
            /** @var DOMElement $element */
            $element->removeAttribute('background');
        }
        foreach (iterator_to_array($xpath->query('//*[@style]')) as $element) {
            /** @var DOMElement $element */
            if (preg_match('/url\s*\(|expression\s*\(|@import/i', $element->getAttribute('style')) === 1) {
                $element->removeAttribute('style');
            }
        }
        foreach (iterator_to_array($xpath->query('//a')) as $a) {
            /** @var DOMElement $a */
            $href = trim($a->getAttribute('href'));
            if ($href === '' || preg_match('~^(https?:|mailto:)~i', $href) !== 1) {
                $a->removeAttribute('href');
                $a->removeAttribute('target');

                continue;
            }
            $a->setAttribute('target', '_blank');
            $a->setAttribute('rel', 'noopener noreferrer nofollow');
        }

        $root = $doc->getElementById('mc-root') ?? $xpath->query('//div[@id="mc-root"]')->item(0);
        $out = '';
        foreach ($root?->childNodes ?? [] as $child) {
            $out .= $doc->saveHTML($child);
        }

        return [$out, $remote, $blocked];
    }

    private function document(string $body, bool $allowRemote): string
    {
        $images = "'self' data:".($allowRemote ? ' https: http:' : '');
        $csp = "default-src 'none'; img-src {$images}; style-src 'unsafe-inline'; font-src 'none'; script-src 'none'; object-src 'none'; frame-src 'none'; form-action 'none'; base-uri 'none'";

        // $csp is a fixed ASCII string without double quotes or markup, written as it is.
        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            .'<meta http-equiv="Content-Security-Policy" content="'.$csp.'">'
            .'<meta name="referrer" content="no-referrer">'
            .'<base target="_blank">'
            .'<style>html{background:#fff}body{margin:0;padding:16px 18px;color:#1f2933;background:#fff;font:14px/1.55 -apple-system,"Segoe UI",Roboto,"Noto Sans Bengali","Noto Sans",Arial,sans-serif;overflow-wrap:anywhere;word-break:break-word}'
            .'img{max-width:100%;height:auto}table{max-width:100%}pre{white-space:pre-wrap}a{color:#0b6bcb}'
            .'.mc-blocked-image{outline:1px dashed #9aa5b1;background:#f1f4f7;min-width:24px;min-height:24px}</style>'
            .'</head><body>'.$body.'</body></html>';
    }
}
