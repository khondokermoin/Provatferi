<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * §MAIL-007 incident, 2026-09-24: public/js/ was never in build-release.sh's
 * packaging list or release-manager.php's switch-time sync (both fixed
 * alongside this test — see their own comments). A Blade view referencing
 * asset('js/whatever.js') committed and built cleanly every time, yet the
 * file never reached the live docroot: the request 404'd, silently, in
 * production only. This proves every asset('js/...') reference in the repo
 * has a real file behind it, the same defense-in-depth IconSubsetTest gives
 * the icon subset.
 */
class PublicJsAssetContractTest extends TestCase
{
    private function referencedJsAssets(): array
    {
        $referenced = [];
        $dirs = [base_path('resources/views')];
        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($files as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }
                preg_match_all("/asset\\(['\"]js\\/([a-zA-Z0-9._-]+\\.js)['\"]\\)/", (string) file_get_contents($file->getPathname()), $m);
                foreach ($m[1] as $jsFile) {
                    $referenced[$jsFile] = true;
                }
            }
        }

        return array_keys($referenced);
    }

    public function test_every_referenced_js_asset_has_a_real_file(): void
    {
        $referenced = $this->referencedJsAssets();

        $this->assertNotEmpty($referenced, 'sanity check: this should find at least the theme + password-toggle scripts');

        $missing = array_values(array_filter(
            $referenced,
            fn (string $file) => ! is_file(public_path('js/'.$file)),
        ));

        $this->assertSame([], $missing, 'Blade views reference these public/js/ files but they do not exist on disk: '.implode(', ', $missing));
    }

    public function test_public_js_is_packaged_by_the_build_script(): void
    {
        $script = (string) file_get_contents(base_path('deploy/build-release.sh'));

        $this->assertMatchesRegularExpression(
            '/tar -cf "\$PUBLIC_TAR".*?\bjs\b/s',
            $script,
            'build-release.sh must include the public/js directory in public-assets.tar'
        );
    }

    public function test_public_js_is_synced_by_the_switch_step(): void
    {
        $script = (string) file_get_contents(base_path('deploy/remote/release-manager.php'));

        $this->assertStringContainsString(
            "public_assets/js', \$PUBLIC_DOCROOT.'/js'",
            $script,
            'release-manager.php\'s switch step must sync public_assets/js into the live docroot'
        );
    }
}
