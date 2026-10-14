<?php

namespace Tests\Concerns;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Submits the admin site-settings form the way a BROWSER does, which the test client does not do by itself.
 *
 * The 2026-10-14 bug lived exactly in that gap: the form's controls were named `site.phone`, a browser posts that name
 * verbatim, and PHP rewrites the dot to an underscore before Laravel sees the request, so the validator never found the
 * field and every real save was refused. A test client passes an array of parameters straight to the request and skips
 * PHP's variable parser, so tests that posted `['site.phone' => ...]` passed against a form that could not save.
 *
 * browserBody() therefore reads the controls of the RENDERED page (name, value, checked, disabled, in document order,
 * the way a browser builds the body), applies the caller's changes, URL-encodes the pairs and runs the string through
 * parse_str(): PHP's own parser, with its own renaming and its own "last value wins" for repeated names (a switch is a
 * hidden 0 followed by the checkbox, so a ticked box wins).
 */
trait SubmitsSettingsLikeABrowser
{
    /** @return list<array{0: string, 1: string}> the name/value pairs a browser would send, in order */
    protected function formPairs(User $admin): array
    {
        $html = $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk()->getContent();

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $form = (new DOMXPath($dom))->query('//form[@id="site-settings-form"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $form, 'The settings screen has no editable form for this user.');

        $pairs = [];
        foreach ((new DOMXPath($dom))->query('.//input[@name]|.//textarea[@name]|.//select[@name]', $form) as $control) {
            /** @var DOMElement $control */
            if ($control->hasAttribute('disabled')) {
                continue;
            }
            $name = $control->getAttribute('name');
            $type = strtolower($control->getAttribute('type'));
            if (in_array($type, ['checkbox', 'radio'], true) && ! $control->hasAttribute('checked')) {
                continue;
            }
            if (in_array($type, ['submit', 'button', 'file'], true)) {
                continue;
            }
            $pairs[] = [$name, $control->nodeName === 'textarea' ? $control->textContent : $control->getAttribute('value')];
        }

        return $pairs;
    }

    /**
     * What Laravel receives from a browser submit of the page, with $changes applied (a field name => its new value;
     * a switch takes true/false, true meaning the checkbox is ticked).
     *
     * @param  array<string, string|bool|null>  $changes
     * @return array<string, mixed>
     */
    protected function browserBody(User $admin, array $changes = []): array
    {
        $pairs = $this->formPairs($admin);

        foreach ($changes as $name => $value) {
            $isSwitch = str_ends_with($name, '_enabled');
            // Whatever the page sent for this control is replaced; a switch that is off sends only its hidden 0.
            $pairs = array_values(array_filter($pairs, fn (array $pair) => $pair[0] !== $name));
            $pairs[] = [$name, $isSwitch ? ($value ? '1' : '0') : (string) $value];
        }

        $body = implode('&', array_map(fn (array $pair) => rawurlencode($pair[0]).'='.rawurlencode($pair[1]), $pairs));
        parse_str($body, $parsed);

        return $parsed;
    }

    /** @param array<string, string|bool|null> $changes */
    protected function saveSettingsLikeABrowser(User $admin, array $changes = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($admin)->put(route('admin.settings.update'), $this->browserBody($admin, $changes));
    }
}
