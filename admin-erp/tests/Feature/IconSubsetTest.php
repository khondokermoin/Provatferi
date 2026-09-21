<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The admin layout loads a generated subset of Tabler Icons (82 of 5,936
 * glyphs) instead of the vendor's full set, which cut ~1 MB off every cold
 * page load. The failure mode that buys back is silent: add a new ti-* icon
 * to a Blade file, forget to re-run build-icon-subset.mjs, and it renders as
 * a blank box in production with no error anywhere. This fails the build
 * instead.
 */
class IconSubsetTest extends TestCase
{
    private function usedIconClasses(): array
    {
        $used = [];
        foreach ([base_path('resources/views'), base_path('resources/css'), public_path('js')] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($files as $file) {
                if (! $file->isFile() || ! preg_match('/\.(blade\.php|php|css|js)$/', $file->getFilename())) {
                    continue;
                }
                preg_match_all('/\bti-[a-z0-9-]+/', (string) file_get_contents($file->getPathname()), $m);
                foreach ($m[0] as $icon) {
                    $used[$icon] = true;
                }
            }
        }

        return array_keys($used);
    }

    public function test_the_generated_subset_files_exist(): void
    {
        $this->assertFileExists(public_path('brand/icons/provatferi-icons.css'));
        $this->assertFileExists(public_path('brand/icons/provatferi-icons.woff2'));
    }

    public function test_every_icon_the_app_renders_is_in_the_subset(): void
    {
        $css = (string) file_get_contents(public_path('brand/icons/provatferi-icons.css'));

        $missing = array_values(array_filter(
            $this->usedIconClasses(),
            fn (string $icon) => ! str_contains($css, ".{$icon}:before"),
        ));

        $this->assertSame([], $missing, 'Icons referenced in the app but absent from the subset — re-run `node build-icon-subset.mjs` and commit public/brand/icons/: '.implode(', ', $missing));
    }

    public function test_the_layout_loads_the_subset_and_not_the_full_vendor_icon_sheet(): void
    {
        $layout = (string) file_get_contents(base_path('resources/views/layouts/admin.blade.php'));

        $this->assertStringContainsString('brand/icons/provatferi-icons.css', $layout);
        $this->assertStringNotContainsString('zircos/css/icons.min.css', $layout);
    }
}
