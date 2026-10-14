<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * PHP rewrites every dot and space in an incoming form-field NAME to an underscore before Laravel sees the request, so a
 * control named `site.phone` arrives as `site_phone` and a validator keyed `site.phone` never finds it (the 2026-10-14
 * bug: the site settings screen could not save at all, and nothing in the test suite noticed because the test client
 * does not go through PHP's variable parser).
 *
 * This scans every Blade view for a form control whose static name contains a dot or a space, so the next form that is
 * written that way fails here instead of in production. Bracket notation (`items[0][title]`) is fine: PHP keeps what is
 * inside the brackets. Names built with {{ }} are skipped (they cannot be judged statically).
 */
class AdminFormFieldNamesTest extends TestCase
{
    /** @return array<int, string> */
    private function bladeFiles(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function test_no_form_control_is_named_with_a_dot_or_a_space(): void
    {
        $offenders = [];
        $controls = '/<(?:input|select|textarea|x-admin\.form-input|x-admin\.form-select|x-admin\.form-textarea|x-admin\.bilingual-field)\b[^>]*?\sname\s*=\s*"([^"]*)"/s';
        $scanned = 0;

        foreach ($this->bladeFiles() as $path) {
            $scanned++;
            preg_match_all($controls, (string) file_get_contents($path), $matches);
            foreach ($matches[1] as $name) {
                if (str_contains($name, '{{')) {
                    continue;
                }
                $outsideBrackets = (string) preg_replace('/\[[^\]]*\]/', '', $name);
                if (str_contains($outsideBrackets, '.') || preg_match('/\s/', $outsideBrackets) === 1) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).": name=\"{$name}\"";
                }
            }
        }

        $this->assertGreaterThan(50, $scanned, 'the scan found too few views: the path is wrong, so it protects nothing');
        $this->assertSame([], $offenders, "PHP rewrites a dot in a field name to an underscore: use underscores (or brackets).\n".implode("\n", $offenders));
    }

    public function test_the_scan_would_catch_the_original_mistake(): void
    {
        $controls = '/<(?:input|x-admin\.form-input)\b[^>]*?\sname\s*=\s*"([^"]*)"/s';

        preg_match_all($controls, '<x-admin.form-input name="site.phone" label="x" />', $matches);

        $this->assertSame(['site.phone'], $matches[1]);
    }
}
