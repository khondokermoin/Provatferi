<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Phase 3 Step 5: a permanent, automated version of the final bidirectional
 * hardcoded-string scan performed manually at the end of Phase 3 — so a
 * future PR that pastes a raw Bengali literal into a Blade view (instead of
 * adding a lang/{bn,en}/admin.php key and calling __()) fails a test instead
 * of silently shipping an English-locale page with Bangla text stuck in it.
 *
 * This only automates the Bengali-script direction: a raw Bengali literal in
 * a .blade.php file is unambiguous — either it's inside a Blade comment
 * (never rendered) or it's real user-facing text that needs a translation
 * key. The reverse direction (a stray English literal that should have
 * followed the admin locale) has no such unambiguous signal — most English
 * in these files is legitimately permanent (HTML/CSS, route names, `PDF`,
 * `URL`, `WhatsApp`, brand names) — so that direction was swept manually
 * (see the Phase 3 commit that fixed admin/content/mission.blade.php,
 * admin/content/vision.blade.php, admin/roles/index.blade.php,
 * admin/membership/types/index.blade.php, admin/activities/index.blade.php
 * and admin/users/show.blade.php) and is instead covered by targeted
 * per-string regression tests in
 * tests/Feature/Admin/HardcodedEnglishStringsFixedTest.php.
 */
class HardcodedStringScanTest extends TestCase
{
    /**
     * Bengali substrings allowed to appear as a raw literal outside __() —
     * genuine institutional identity/branding, never UI chrome that needs to
     * follow the admin's locale, keyed by the file that may contain them.
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED = [
        'resources/views/admin/recruitment/applications/document.blade.php' => [
            'প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র',
        ],
    ];

    /** @return array<int, string> */
    private function bladeFiles(): array
    {
        $dirs = [
            resource_path('views/admin'),
            resource_path('views/components/admin'),
            resource_path('views/layouts'),
            resource_path('views/errors'),
            resource_path('views/auth'),
        ];

        $files = [];
        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    public function test_no_unexplained_raw_bengali_literal_exists_outside_blade_comments(): void
    {
        $violations = [];

        $normalizedBasePath = str_replace('\\', '/', base_path()).'/';

        foreach ($this->bladeFiles() as $path) {
            $relative = str_replace($normalizedBasePath, '', str_replace('\\', '/', $path));
            $allowedForFile = self::ALLOWED[$relative] ?? [];

            $content = file_get_contents($path);
            // Blade comments {{-- ... --}} never render to the browser — a
            // developer-facing note in Bangla there is not a UI string.
            $withoutComments = preg_replace('/\{\{--.*?--\}\}/s', '', $content);

            foreach (explode("\n", $withoutComments) as $lineNo => $line) {
                if (! preg_match('/[\x{0980}-\x{09FF}]/u', $line)) {
                    continue;
                }

                $isAllowed = false;
                foreach ($allowedForFile as $allowedSubstring) {
                    if (str_contains($line, $allowedSubstring)) {
                        $isAllowed = true;
                        break;
                    }
                }

                if (! $isAllowed) {
                    $violations[] = $relative.':'.($lineNo + 1).': '.trim($line);
                }
            }
        }

        $this->assertSame([], $violations, "Unexplained raw Bengali literal(s) found outside __()/allowlist:\n".implode("\n", $violations));
    }
}
